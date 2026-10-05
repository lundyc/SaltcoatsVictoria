#!/usr/bin/env python3
"""Fill the club's opposition-report PowerPoint template from a COMET JSON export.

    build_deck.py --json opponent.json --template opposition_template.pptx \
                  [--league wosfl_table.json] --out report.pptx

The template *is* the layout: every slide keeps its shapes, fonts and colours and
only text / table rows change, so the weekly deck always matches the reference deck.
"""
import argparse
import copy
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, 'pylib'))
sys.path.insert(0, HERE)

from pptx import Presentation  # noqa: E402
from pptx.oxml.ns import qn  # noqa: E402

import stats  # noqa: E402

OUR_CLUB = 'saltcoats'
WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
         'eleven', 'twelve']


def word(n):
    return WORDS[n] if 0 <= n < len(WORDS) else str(n)


def plural(n, one, many=None):
    return one if n == 1 else (many or one + 's')


# ---------------------------------------------------------------- text helpers

def shape(slide, sid):
    for sh in slide.shapes:
        if sh.shape_id == sid:
            return sh
    raise KeyError('shape %s not on slide' % sid)


def set_runs(target, texts):
    """Put `texts` into the runs of a shape's first paragraph, keeping each run's formatting."""
    para = target.text_frame.paragraphs[0] if hasattr(target, 'text_frame') else target
    runs = para.runs
    while len(runs) < len(texts):                       # need more runs: clone the last one
        clone = copy.deepcopy(runs[-1]._r)
        runs[-1]._r.addnext(clone)
        runs = para.runs
    for i, r in enumerate(runs):
        r.text = texts[i] if i < len(texts) else ''


def set_text(sh, text):
    set_runs(sh, [text])


def set_lines(sh, lines):
    """One paragraph per line, cloned from the shape's first paragraph (keeps its formatting)."""
    txbody = sh.text_frame._txBody
    paras = txbody.findall(qn('a:p'))
    proto = copy.deepcopy(paras[0])
    for p in paras:
        txbody.remove(p)
    for line in lines or ['']:
        p = copy.deepcopy(proto)
        runs = p.findall(qn('a:r'))
        for extra in runs[1:]:
            p.remove(extra)
        for br in p.findall(qn('a:br')):
            p.remove(br)
        runs[0].find(qn('a:t')).text = line
        rpr = runs[0].find(qn('a:rPr'))
        if rpr is not None:
            rpr.attrib.pop('err', None)
        txbody.append(p)


def drop_shape(sh):
    sh._element.getparent().remove(sh._element)


def drop_slide(prs, slide):
    lst = prs.slides._sldIdLst
    for sld in list(lst):
        if prs.slides.get(int(sld.get('id'))) is slide:
            prs.part.drop_rel(sld.rId)
            lst.remove(sld)
            return


# --------------------------------------------------------------- table helpers

def _set_fill(tc, hex_):
    node = tc.tcPr.find('.//' + qn('a:srgbClr'))
    if node is not None:
        node.set('val', hex_)


def _set_cell(tc, text):
    p = tc.txBody.find(qn('a:p'))
    runs = p.findall(qn('a:r'))
    for extra in runs[1:]:
        p.remove(extra)
    runs[0].find(qn('a:t')).text = text
    rpr = runs[0].find(qn('a:rPr'))
    if rpr is not None:
        rpr.attrib.pop('err', None)


def rebuild_table(gf, rows, proto_idx, fills, budget=None):
    """Replace body rows of a graphic-frame table.

    rows       list of (cell texts, style) — style picks a prototype row index from proto_idx
    proto_idx  {style: template body row index} — prototype rows carry the fonts/bold/colour
    fills      callable(i, style) -> hex fill for body row i, or None to keep the prototype's
    budget     max total height in EMU; rows shrink (and text with them) if they'd overflow
    """
    tbl = gf.table._tbl
    trs = tbl.findall(qn('a:tr'))
    protos = {k: copy.deepcopy(trs[v]) for k, v in proto_idx.items()}
    base_h = int(trs[1].get('h'))
    for tr in trs[1:]:
        tbl.remove(tr)
    head_h = int(trs[0].get('h'))
    budget = budget or (head_h + base_h * (len(trs) - 1))
    row_h = min(base_h, (budget - head_h) // max(1, len(rows)))
    scale = 1.0 if row_h >= base_h * 0.85 else max(0.6, row_h / base_h)
    for i, (texts, style) in enumerate(rows):
        tr = copy.deepcopy(protos[style])
        tr.set('h', str(row_h))
        cells = tr.findall(qn('a:tc'))
        for tc, text in zip(cells, texts):
            _set_cell(tc, text)
            fill = fills(i, style)
            if fill:
                _set_fill(tc, fill)
            if scale < 1.0:
                for rpr in tc.iter(qn('a:rPr')):
                    if rpr.get('sz'):
                        rpr.set('sz', str(int(int(rpr.get('sz')) * scale)))
        tbl.append(tr)
    gf.height = head_h + row_h * len(rows)


def zebra(i, style):
    return 'FFFFFF' if i % 2 == 0 else 'F5F1EB'      # first body row white, then cream


def hi_zebra(i, style):
    return 'FFF1C4' if style == 'hi' else zebra(i, style)


# ------------------------------------------------------------------- the deck

def season_year(comp):
    return re.sub(r'\b(\d{2})/(\d{2})$', r'20\1/\2', comp.strip())


def league_title(comp):
    base = re.sub(r'\s*\d{2,4}/\d{2,4}$', '', comp.strip())
    return re.sub(r'^West of Scotland', 'WOSFL', base) + ' — league table'


def numtag(num, hash_=True):
    return ('#' + num if hash_ else num) if num else ('–' if not hash_ else '–')


def load_league(path):
    try:
        rows = json.load(open(path))
        return rows if isinstance(rows, list) and rows else None
    except (OSError, ValueError, TypeError):
        return None


def build_deck(data, template, league, out):
    r = stats.build(data)
    prs = Presentation(template)
    s = list(prs.slides)
    n, sm, team = r['n'], r['summary'], stats.clean_team(r['team'])
    if n == 0:
        raise SystemExit('No played matches in that file.')
    team_up = team.upper() + ('' if team.upper().endswith(' FC') else ' FC')
    has_lineups = bool(r['starters'])

    # 1 — cover
    set_text(shape(s[0], 6), team_up)
    set_text(shape(s[0], 7), season_year(r['competition']))
    set_text(shape(s[0], 8), '%d LEAGUE %s VERIFIED' % (n, plural(n, 'MATCH', 'MATCHES')))

    # 2 — snapshot
    tiles = {10: sm['p'], 13: sm['w'], 16: sm['l'], 19: sm['gf'], 22: sm['ga'], 25: sm['cs']}
    if sm['d']:
        set_text(shape(s[1], 12), 'WINS / DRAWS')
        tiles[13] = '%d / %d' % (sm['w'], sm['d'])
    for sid, val in tiles.items():
        set_text(shape(s[1], sid), str(val))
    for sid, item, fmt in ((27, 0, 'c'), (28, 1, 'c'), (30, 0, 'k'), (31, 1, 'k')):
        src = r['captains'] if fmt == 'c' else r['keepers']
        if item < len(src):
            e = src[item]
            set_text(shape(s[1], sid), '%s — %s' % (e['name'], ('captain in %d %s' % (e['n'], plural(e['n'], 'match', 'matches')))
                                                     if fmt == 'c' else '%d %s' % (e['n'], plural(e['n'], 'start'))))
        else:
            set_text(shape(s[1], sid), '')

    # 3 — results
    set_text(shape(s[2], 5), '%s league %s in chronological round order' % (word(n).capitalize(), plural(n, 'match', 'matches')))
    rebuild_table(shape(s[2], 8), [([x['opponent'], '%d–%d' % (x['for_'], x['against']), x['outcome']], 'row') for x in r['results']],
                  {'row': 1}, zebra, budget=4526280)
    set_text(shape(s[2], 11), str(sm['gf']))
    set_text(shape(s[2], 14), str(sm['ga']))
    form_box = shape(s[2], 16)
    win_run = next((x for x in form_box.text_frame.paragraphs[0].runs if x.text == 'W'), None)
    plain_run = next(x for x in form_box.text_frame.paragraphs[0].runs if x.text.strip() == 'L')
    win_el, plain_el = copy.deepcopy(win_run._r), copy.deepcopy(plain_run._r)
    para = form_box.text_frame.paragraphs[0]._p
    for x in para.findall(qn('a:r')):
        para.remove(x)
    anchor = para.find(qn('a:endParaRPr'))
    for i, o in enumerate(r['form']):
        el = copy.deepcopy(win_el if o == 'W' else plain_el)
        el.find(qn('a:t')).text = o
        (anchor.addprevious if anchor is not None else para.append)(el)
        if i < len(r['form']) - 1:
            sp = copy.deepcopy(plain_el)
            sp.find(qn('a:t')).text = '  '
            (anchor.addprevious if anchor is not None else para.append)(sp)
    lx = r['latest']
    set_runs(shape(s[2], 18), [str(lx['score'][0]), ' – ', str(lx['score'][1]), lx['home_short'], ' vs ' + lx['away_short']])

    # 4 — goal threats
    goals = r['goals']
    sub4 = shape(s[3], 5)
    sub4.width = shape(s[2], 5).width
    set_text(sub4, '%d league %s across the %d-match sample' % (sm['gf'], plural(sm['gf'], 'goal'), n))
    rows = [([g['number'] or '–', g['name'], str(g['goals'])], 'hi' if g['goals'] >= 2 else 'lo') for g in goals[:8]] \
        or [(['–', 'No goals recorded', '0'], 'lo')]
    rebuild_table(shape(s[3], 8), rows, {'hi': 1, 'lo': 3}, hi_zebra, budget=4251960)
    if goals:
        top = goals[0]
        set_text(shape(s[3], 10), top['name'])
        set_text(shape(s[3], 11), '%d %s' % (top['goals'], 'GOAL' if top['goals'] == 1 else 'GOALS'))
        who = ('#%s ' % top['number'] if top['number'] else '') + top['name']
        set_runs(shape(s[3], 17), ['KEY THREAT:', ' ', '%s has scored ' % who,
                                   '%d %s in %d league %s' % (top['goals'], plural(top['goals'], 'goal'), n, plural(n, 'match', 'matches'))])
    else:
        set_text(shape(s[3], 10), '—')
        set_text(shape(s[3], 11), '0 GOALS')
        set_runs(shape(s[3], 17), ['KEY THREAT:', ' ', 'No goals recorded ', 'in %d league %s' % (n, plural(n, 'match', 'matches'))])

    # 5 — most-used starters   (needs lineups in the export)
    if has_lineups:
        rows = [([numtag(x['number']), x['name'], '%d/%d' % (x['starts'], n), str(x['goals']), str(x['yc'])],
                 'hi' if x['starts'] >= 0.75 * n else 'lo') for x in r['starters'][:10]]
        rebuild_table(shape(s[4], 8) if any(sh.shape_id == 8 and sh.has_table for sh in s[4].shapes) else
                      next(sh for sh in s[4].shapes if sh.has_table), rows, {'hi': 1, 'lo': 8}, hi_zebra)
    else:
        drop_slide(prs, s[4])

    # 6 — latest starting XI
    xi = r['latest']['players'] if r['latest'] else []
    if has_lineups and xi:
        s6 = s[5]
        num_ids = [9, 12, 15, 18, 21, 24, 27, 30, 33, 36, 39]
        name_ids = [x + 1 for x in num_ids]
        rect_ids = [x - 1 for x in num_ids]
        cap_suffix = copy.deepcopy(shape(s6, 34).text_frame.paragraphs[0].runs[1]._r)
        for i in range(11):
            if i >= len(xi):
                for sid in (rect_ids[i], num_ids[i], name_ids[i]):
                    drop_shape(shape(s6, sid))
                continue
            p = xi[i]
            set_text(shape(s6, num_ids[i]), '#%s' % p['number'] if p['number'] is not None else '–')
            box = shape(s6, name_ids[i])
            para = box.text_frame.paragraphs[0]
            runs = para.runs
            runs[0].text = p['name']
            for extra in runs[1:]:
                para._p.remove(extra._r)
            if p['captain']:
                para._p.append(copy.deepcopy(cap_suffix))
        L = r['latest']
        set_runs(shape(s6, 5), ['%s %s–%s %s • Round %s' % (L['home'], L['score'][0], L['score'][1], L['away'], L['round']), '', ' •  ',
                                'NOTE: THIS IS NOT THEIR STARTING XI TODAY !!!'])
        cap = next((p for p in xi if p['captain']), None)
        gk = next((p for p in xi if p['gk']), None)
        parts = []
        if cap:
            parts.append('Captain in this match: %s%s' % (cap['name'], ' (#%s)' % cap['number'] if cap['number'] is not None else ''))
        if gk:
            parts.append('Goalkeeper: %s%s' % (gk['name'], ' (#%s)' % gk['number'] if gk['number'] is not None else ''))
        set_runs(shape(s6, 41), ['  •  '.join(parts), '', ''])
    elif not has_lineups:
        drop_slide(prs, s[5])

    # 7 — substitutions
    intro = r['intro']
    rows = [([x['name'], str(x['on'])], 'hi' if x['on'] >= 3 else 'lo') for x in intro[:5]] \
        or [(['No substitutions recorded', '0'], 'lo')]
    s7tbl = next(sh for sh in s[6].shapes if sh.has_table)
    rebuild_table(s7tbl, rows, {'hi': 1, 'lo': 5}, lambda i, st: 'FFF1C4' if st == 'hi' else 'FFFFFF', budget=858012 * 5 + 427220)
    if intro:
        top = intro[0]
        set_text(shape(s[6], 10), top['raw'])
        set_text(shape(s[6], 11), '%d substitute %s' % (top['on'], plural(top['on'], 'appearance')))
        rest = [x for x in intro[1:] if x['on'] == (intro[1]['on'] if len(intro) > 1 else 0)]
        if rest:
            names = [x['raw'] for x in rest]
            who = names[0] if len(names) == 1 else ', '.join(names[:-1]) + ' and ' + names[-1]
            sent = '%s %s introduced %d %s.' % (who, 'was' if len(names) == 1 else 'were each', rest[0]['on'], plural(rest[0]['on'], 'time'))
        else:
            sent = ''
        set_runs(shape(s[6], 12), [sent, '', ''])
    else:
        set_text(shape(s[6], 10), '—')
        set_text(shape(s[6], 11), 'No substitutes used')
        set_runs(shape(s[6], 12), ['', '', ''])
    box = shape(s[6], 20)
    para = box.text_frame.paragraphs[0]._p
    runs = para.findall(qn('a:r'))
    bold_el, plain_el, br_el = copy.deepcopy(runs[0]), copy.deepcopy(runs[2]), copy.deepcopy(para.find(qn('a:br')))
    for el in runs + para.findall(qn('a:br')):
        para.remove(el)
    pairs = r['pairs'][:3]
    for i, pr in enumerate(pairs):
        label = ('#%s ' % pr['out']['number'] if pr['out']['number'] else '') + '%s → %s' % (pr['out']['name'], pr['into'])
        b, pl = copy.deepcopy(bold_el), copy.deepcopy(plain_el)
        b.find(qn('a:t')).text = label
        pl.find(qn('a:t')).text = ' — %d %s' % (pr['n'], plural(pr['n'], 'time'))
        if b.find(qn('a:rPr')) is not None:
            b.find(qn('a:rPr')).attrib.pop('err', None)
        para.append(b)
        para.append(pl)
        if i < len(pairs) - 1:
            para.append(copy.deepcopy(br_el))
    if not pairs:
        b = copy.deepcopy(plain_el)
        b.find(qn('a:t')).text = 'No like-for-like changes recorded'
        para.append(b)

    # 8 — discipline
    carded = r['carded']
    set_text(shape(s[7], 5), 'Yellow cards derived from %s match events in the supplied JSON' % team)
    rows = [([x['number'] or '–', x['name'], str(x['yc'])], 'hi' if x['yc'] >= 2 else 'lo') for x in carded[:10]] \
        or [(['–', 'No yellow cards recorded', '0'], 'lo')]
    s8tbl = next(sh for sh in s[7].shapes if sh.has_table)
    rebuild_table(s8tbl, rows, {'hi': 1, 'lo': 4}, hi_zebra)
    top_yc = carded[0]['yc'] if carded else 0
    worst = [x for x in carded if x['yc'] == top_yc][:3] if carded else []
    set_lines(shape(s[7], 10), [('#%s ' % x['number'] if x['number'] else '') + x['name'] for x in worst] or ['—'])
    set_text(shape(s[7], 11), ('%d %s%s' % (top_yc, ('YELLOW' if top_yc == 1 else 'YELLOWS'), ' EACH' if len(worst) > 1 else '')) if worst else 'NONE RECORDED')
    reds = r['reds']
    set_text(shape(s[7], 12), ('No %s red-card events are recorded in the %s supplied league %s.' % (team, word(n), plural(n, 'match', 'matches')))
             if not reds else '%s %s red %s recorded in the %s supplied league %s.' % (team, word(reds), plural(reds, 'card'), word(n), plural(n, 'match', 'matches')))

    # 9 — league table
    if league:
        s9 = s[8]
        set_text(shape(s9, 4), league_title(r['competition']))
        tgt = team.lower()
        m = len(league)
        rows = []
        for i, row in enumerate(league):
            club = str(row.get('club', ''))
            low = club.lower()
            style = 'ours' if OUR_CLUB in low else 'them' if (low == tgt or low.startswith(tgt) or tgt.startswith(low)) else 'zone' if i >= m - 3 and m > 6 else 'row'
            rows.append(([str(row.get('pos', i + 1)), club, str(row.get('p', '')), str(row.get('gd', '')), str(row.get('pts', ''))], style))
        s9tbl = next(sh for sh in s9.shapes if sh.has_table)
        fills = lambda i, st: ('FFFFFF' if i % 2 == 0 else 'F8F6F2') if st == 'row' else None
        rebuild_table(s9tbl, rows, {'row': 1, 'ours': 11, 'them': 12, 'zone': 14}, fills, budget=4900000)
    else:
        drop_slide(prs, s[8])

    # closing image slide is no longer wanted
    drop_slide(prs, s[9])

    prs.core_properties.title = '%s — Opposition Report' % team
    prs.save(out)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--json', required=True)
    ap.add_argument('--template', required=True)
    ap.add_argument('--league')
    ap.add_argument('--out', required=True)
    a = ap.parse_args()
    with open(a.json, encoding='utf-8') as fh:
        data = json.load(fh)
    build_deck(data, a.template, load_league(a.league) if a.league else None, a.out)


if __name__ == '__main__':
    main()
