#!/usr/bin/env python3
"""Background job: COMET JSON -> deck -> MP4, reporting progress in a status file.

    video_job.py --json x.json --template t.pptx [--league l.json] --mp4 out.mp4 --status out.json
"""
import argparse
import json
import os
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


def write_status(path, **fields):
    tmp = path + '.tmp'
    with open(tmp, 'w') as fh:
        json.dump(fields, fh)
    os.replace(tmp, path)


def main():
    ap = argparse.ArgumentParser()
    for a in ('json', 'template', 'mp4', 'status'):
        ap.add_argument('--' + a, required=True)
    ap.add_argument('--league')
    a = ap.parse_args()
    started = int(time.time())
    write_status(a.status, state='running', started=started, step='Building slides')
    tmp_mp4 = a.mp4 + '.part'
    try:
        import build_deck
        import make_video
        with open(a.json, encoding='utf-8') as fh:
            data = json.load(fh)
        deck = tempfile.mktemp(suffix='.pptx', prefix='opp_deck_')
        try:
            build_deck.build_deck(data, a.template, build_deck.load_league(a.league) if a.league else None, deck)
            write_status(a.status, state='running', started=started, step='Rendering video')
            make_video.render(deck, tmp_mp4)
        finally:
            if os.path.exists(deck):
                os.unlink(deck)
        os.replace(tmp_mp4, a.mp4)
        write_status(a.status, state='done', started=started, finished=int(time.time()))
    except BaseException as e:  # SystemExit from the helpers carries their message
        if os.path.exists(tmp_mp4):
            os.unlink(tmp_mp4)
        write_status(a.status, state='error', started=started, message=str(e)[:400])
        sys.exit(1)


if __name__ == '__main__':
    main()
