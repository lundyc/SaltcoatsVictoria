#!/usr/bin/env python3
"""Read COMET's two-column reports; retain page/rectangle evidence. No writes."""
import json
import re
import sys
import fitz

HEADINGS = {'MATCH OFFICIALS', 'LINEUPS', 'SUBSTITUTES', 'STAFF', 'GOALS',
            'DISCIPLINARY', 'YELLOW CARDS', 'RED CARDS', 'SECOND YELLOW', 'SUBSTITUTIONS'}


def extract(path):
    doc = fitz.open(path)
    if doc.needs_pass or len(doc) > 30:
        raise ValueError('Encrypted PDFs and reports over 30 pages are not supported.')
    groups = {}
    evidence = []
    raw = []
    previous = 'HEADER'
    for page in doc:
        raw.append(page.get_text())
        blocks = [b for b in page.get_text('blocks') if b[6] == 0]
        headings = sorted([(b[1], b[4].strip()) for b in blocks if b[4].strip() in HEADINGS])
        for b in blocks:
            text = b[4].strip()
            if not text:
                continue
            section = previous
            for y, heading in headings:
                if y <= b[1] + 1:
                    section = heading
            if text in HEADINGS:
                continue
            if 'COMET - Scottish Football Association' in text or text.startswith('Match report:'):
                section = 'REPORT METADATA'
            elif 'check_circle' in text or text.startswith('My Scottish Football') or 'label.report.match' in text:
                section = 'CONFIRMATIONS'
            side = 'home' if b[0] < page.rect.width / 2 else 'away'
            item = {'page': page.number + 1, 'rect': [round(v, 2) for v in b[:4]],
                    'section': section, 'side': side, 'text': text}
            evidence.append(item)
            groups.setdefault(section, {}).setdefault(side, []).append(item)
        if headings:
            previous = headings[-1][1]
    if not ''.join(raw).strip():
        raise ValueError('No readable text. This PDF needs OCR before importing.')
    rosters = {'home': [], 'away': []}
    warnings = []
    for section, starting in [('LINEUPS', True), ('SUBSTITUTES', False)]:
        for side in rosters:
            for block in groups.get(section, {}).get(side, []):
                text = ' '.join(block['text'].split())
                # A player can carry more than one marker, e.g. "Adam Love CP T 812345 SCO"
                # (captain AND trialist) or "Andrew Finnigan GK T ...". Capture the whole run.
                m = re.fullmatch(
                    r'(\d{1,2})\s+(.+?)\s+(?:((?:GK|CP|G|C|T)(?:\s+(?:GK|CP|G|C|T))*)\s+)?(\d{5,12})\s+(SCO|N/A|[A-Z]{3})',
                    text)
                if not m:
                    if re.match(r'^\d', text):
                        warnings.append('Unreadable player row on page %s: %s' % (block['page'], text))
                    continue
                marker = ' '.join((m[3] or '').split())
                rosters[side].append({'number': int(m[1]), 'name': m[2], 'marker': marker,
                                      'registration_id': m[4], 'nationality': m[5], 'starting': starting,
                                      'page': block['page'], 'rect': block['rect']})
    return {'raw_text': '\n'.join(raw), 'groups': groups, 'rosters': rosters,
            'evidence': evidence, 'warnings': warnings, 'pages': len(doc)}


if __name__ == '__main__':
    try:
        print(json.dumps(extract(sys.argv[1]), ensure_ascii=False))
    except Exception as error:
        print(json.dumps({'error': str(error)}))
        sys.exit(1)
