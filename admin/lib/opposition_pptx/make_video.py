#!/usr/bin/env python3
"""Render an opposition-report .pptx to an MP4 that follows the deck's own timings.

    make_video.py --pptx report.pptx --out report.mp4

Each slide is drawn by LibreOffice, held for its auto-advance time (advTm) and
cross-faded into the next over the deck's transition duration. LibreOffice can't
play PowerPoint's Morph transition, so a fade stands in for it (Morph's own
fallback in the file is a fade too).
"""
import argparse
import glob
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

DEFAULT_HOLD_MS = 15000
DEFAULT_FADE_MS = 2000
FPS = 25


def slide_timings(pptx):
    """[(hold_ms, fade_ms)] per slide in presentation order."""
    with zipfile.ZipFile(pptx) as z:
        pres = z.read('ppt/presentation.xml').decode('utf8')
        rels = z.read('ppt/_rels/presentation.xml.rels').decode('utf8')
        target = dict(re.findall(r'<Relationship [^>]*?Id="([^"]+)"[^>]*?Target="([^"]+)"', rels))
        target.update({b: a for a, b in re.findall(r'<Relationship [^>]*?Target="([^"]+)"[^>]*?Id="([^"]+)"', rels)})
        out = []
        for rid in re.findall(r'<p:sldId [^>]*?r:id="([^"]+)"', pres):
            xml = z.read('ppt/' + target[rid]).decode('utf8')
            adv = re.search(r'advTm="(\d+)"', xml)
            dur = re.search(r'p14:dur="(\d+)"', xml)
            out.append((int(adv.group(1)) if adv else DEFAULT_HOLD_MS, int(dur.group(1)) if dur else DEFAULT_FADE_MS))
        return out


def run(cmd, **kw):
    p = subprocess.run(cmd, capture_output=True, text=True, **kw)
    if p.returncode != 0:
        sys.exit('%s failed: %s' % (cmd[0], (p.stderr or p.stdout)[-800:]))


def render(pptx, out):
    timings = slide_timings(pptx)
    work = tempfile.mkdtemp(prefix='opp_video_')
    try:
        shutil.copy(pptx, os.path.join(work, 'deck.pptx'))
        # private profile dir so concurrent runs (and the server's own soffice) never fight over a lock
        run(['soffice', '-env:UserInstallation=file://' + os.path.join(work, 'profile'), '--headless',
             '--convert-to', 'pdf', '--outdir', work, os.path.join(work, 'deck.pptx')], timeout=240)
        run(['pdftoppm', '-r', '144', '-png', os.path.join(work, 'deck.pdf'), os.path.join(work, 's')], timeout=240)
        frames = sorted(glob.glob(os.path.join(work, 's-*.png')), key=lambda f: int(re.findall(r'(\d+)\.png$', f)[0]))
        if not frames:
            sys.exit('No slides rendered.')
        timings = (timings + [(DEFAULT_HOLD_MS, DEFAULT_FADE_MS)] * len(frames))[:len(frames)]
        fade = min(t[1] for t in timings) / 1000.0

        cmd = ['ffmpeg', '-y', '-loglevel', 'error']
        for i, f in enumerate(frames):
            length = timings[i][0] / 1000.0 + (fade if i < len(frames) - 1 else 0)
            cmd += ['-loop', '1', '-framerate', str(FPS), '-t', '%.3f' % length, '-i', f]
        scale = 'scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2,format=yuv420p'
        parts = ['[%d:v]%s[v%d]' % (i, scale, i) for i in range(len(frames))]
        last, offset = 'v0', 0.0
        for i in range(1, len(frames)):
            offset += timings[i - 1][0] / 1000.0
            parts.append('[%s][v%d]xfade=transition=fade:duration=%.3f:offset=%.3f[x%d]' % (last, i, fade, offset, i))
            last = 'x%d' % i
        cmd += ['-filter_complex', ';'.join(parts), '-map', '[%s]' % last, '-c:v', 'libx264', '-preset', 'veryfast',
                '-crf', '23', '-pix_fmt', 'yuv420p', '-r', str(FPS), '-movflags', '+faststart', os.path.join(work, 'out.mp4')]
        run(cmd, timeout=900)
        shutil.move(os.path.join(work, 'out.mp4'), out)
    finally:
        shutil.rmtree(work, ignore_errors=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--pptx', required=True)
    ap.add_argument('--out', required=True)
    a = ap.parse_args()
    render(a.pptx, a.out)


if __name__ == '__main__':
    main()
