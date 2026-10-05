"""Turn a COMET opponent export (JSON) into the numbers behind the scouting deck.

Mirrors admin/lib/opposition_report.php's attribution rules (lineups give full
names/shirt numbers, the flat event list is the source of truth for goals,
cards and substitutions) but also produces the deck-only extras: latest XI,
substitution pairs, form string and captain/goalkeeper tallies.
"""
import re
from collections import Counter, OrderedDict


def team_is_target(name, target):
    name, target = (name or '').strip(), (target or '').strip()
    if not name or not target:
        return False
    if name.lower() == target.lower():
        return True
    return bool(re.match(re.escape(target) + r'(?=[\s.]|$)', name, re.I))


def clean_team(name):
    return re.sub(r'\s+(F\.?C\.?|A\.?F\.?C\.?|J\.?F\.?C\.?)$', '', (name or '').strip(), flags=re.I)


def short_team(name):
    return re.sub(r'\bUnited\b', 'Utd', clean_team(name))


def fix_casing(word):
    def part(p):
        low = p.lower()
        if low.startswith('mc') and len(low) > 2:
            return 'Mc' + low[2:].capitalize()
        if low.startswith('mac') and len(low) > 3:
            return 'Mac' + low[3:].capitalize()
        if low.startswith("o'") and len(low) > 2:
            return "O'" + low[2:].capitalize()
        return low.capitalize()
    return '-'.join(part(p) for p in word.split('-'))


def clean_full_name(name):
    return ' '.join(fix_casing(w) for w in name.split())


_ABBREV = re.compile(r'^(.+?)\s+([A-Za-z])\.?$')


def abbrev_key(raw):
    m = _ABBREV.match((raw or '').strip())
    return (m.group(1).upper() + '|' + m.group(2).upper()) if m else None


def format_abbrev(raw):
    m = _ABBREV.match((raw or '').strip())
    return (m.group(2).upper() + '. ' + fix_casing_words(m.group(1))) if m else (raw or '').strip()


def fix_casing_words(s):
    return ' '.join(fix_casing(w) for w in s.split())


def usual_number(numbers):
    """Most-worn shirt number as text: '7', '5 / 6' when tied, '' when unknown."""
    if not numbers:
        return ''
    top = max(numbers.values())
    tied = sorted(n for n, c in numbers.items() if c == top)
    return ' / '.join(str(n) for n in tied) if len(tied) <= 2 else ''


def round_no(m):
    try:
        return int(m.get('round'))
    except (TypeError, ValueError):
        return 0


def build(data):
    target = (data.get('team') or '').strip()
    matches = [m for m in data.get('matches', [])
               if isinstance(m, dict) and str(m.get('status', '')).upper() == 'PLAYED' and isinstance(m.get('score'), dict)]
    file_order = list(matches)
    matches.sort(key=lambda m: (round_no(m), m.get('dateTimeUTC') or 0))
    n = len(matches)

    players = OrderedDict()   # id -> dict
    by_abbrev = {}            # 'SURNAME|I' -> id (lineup players only)

    def player(pid, full):
        if pid not in players:
            players[pid] = dict(id=pid, full=full, numbers=Counter(), sub_numbers=Counter(), starts=0, captain=0, gk=0,
                                goals=0, yc=0, rc=0, on=0, off=0, lineup=False)
        return players[pid]

    def register_lineup(full):
        parts = full.split()
        pid = full.lower()
        p = player(pid, clean_full_name(full))
        p['lineup'] = True
        if parts:
            for k in range(1, len(parts)):   # every possible surname length
                by_abbrev.setdefault(' '.join(parts[-k:]).upper() + '|' + parts[0][0].upper(), pid)
        return p

    def resolve(raw):
        key = abbrev_key(raw)
        if key and key in by_abbrev:
            p = players[by_abbrev[key]]
        else:
            p = player('ab:' + (key or (raw or '').upper()), format_abbrev(raw))
        p.setdefault('raw', (raw or '').strip())   # COMET's own "Surname I." spelling
        return p

    def linked_number(m, side, out_raw, minute):
        """Shirt number of the player who replaced `out_raw`, read from that starter's lineup row."""
        if not out_raw:
            return None
        out = resolve(out_raw)
        for row in lineup_by_match[id(m)]:
            if not isinstance(row, dict) or (row.get('name') or '').strip().lower() != out['id']:
                continue
            for ev in row.get('rowEvents') or []:
                if ev.get('type') == 'substitution' and ev.get('minute') == minute and isinstance(ev.get('linkedShirtNumber'), int):
                    return ev['linkedShirtNumber']
        return None

    results, form, sub_pairs, sub_in = [], [], Counter(), Counter()
    summary = dict(p=0, w=0, d=0, l=0, gf=0, ga=0, cs=0)
    latest = None

    # lineups are read in file order (COMET lists newest first) so ties resolve stably
    lineup_by_match = {}
    for m in file_order:
        is_home = team_is_target(m.get('homeTeam'), target)
        lineup_by_match[id(m)] = m.get('homeLineup' if is_home else 'awayLineup') or []
    for m in file_order:
        for e in lineup_by_match[id(m)]:
            if isinstance(e, dict) and (e.get('name') or '').strip():
                register_lineup(e['name'].strip())
    for m in file_order:
        for e in lineup_by_match[id(m)]:
            if not isinstance(e, dict) or not (e.get('name') or '').strip():
                continue
            p = players[e['name'].strip().lower()]
            if isinstance(e.get('shirtNumber'), int):
                p['numbers'][e['shirtNumber']] += 1
            p['starts'] += bool(e.get('starter'))
            p['captain'] += bool(e.get('captain'))
            p['gk'] += bool(e.get('goalkeeper'))

    for m in matches:
        is_home = team_is_target(m.get('homeTeam'), target)
        opp = m.get('awayTeam') if is_home else m.get('homeTeam')
        f = int(m['score'].get('home' if is_home else 'away') or 0)
        a = int(m['score'].get('away' if is_home else 'home') or 0)
        outcome = 'W' if f > a else ('L' if f < a else 'D')
        summary['p'] += 1
        summary['gf'] += f
        summary['ga'] += a
        summary[outcome.lower()] += 1
        summary['cs'] += a == 0
        results.append(dict(opponent=clean_team(opp), for_=f, against=a, outcome=outcome, round=round_no(m)))
        form.append(outcome)
        latest = m

        side = 'home' if is_home else 'away'
        for e in m.get('events') or []:
            if not isinstance(e, dict) or e.get('side') != side:
                continue
            t = e.get('type')
            if t in ('goal', 'penalty', 'unknown') and e.get('player'):
                resolve(e['player'])['goals'] += 1
            elif t == 'yellow_card' and e.get('player'):
                resolve(e['player'])['yc'] += 1
            elif t == 'red_card' and e.get('player'):
                resolve(e['player'])['rc'] += 1
            elif t == 'substitution':
                po, pi = e.get('playerOut'), e.get('playerIn')
                if po:
                    resolve(po)['off'] += 1
                if pi:
                    resolve(pi)['on'] += 1
                    sub_in[resolve(pi)['id']] += 1
                if pi:
                    linked = linked_number(m, side, po, e.get('minute'))
                    if linked is not None:
                        resolve(pi)['sub_numbers'][linked] += 1
                if po and pi:
                    sub_pairs[(resolve(po)['id'], resolve(pi)['id'])] += 1

    order = {pid: i for i, pid in enumerate(players)}
    surname = lambda p: p['full'].split()[-1].lower() if p['full'] else ''
    ranked = lambda items, key, *tie: sorted(items, key=lambda p: (-key(p),) + tuple(t(p) for t in tie) + (surname(p), order[p['id']]))
    first_num = lambda p: min(p['numbers']) if p['numbers'] else 99
    plist = list(players.values())

    goal_rows = ranked([p for p in plist if p['goals'] > 0], lambda p: p['goals'])
    starters = ranked([p for p in plist if p['starts'] > 0], lambda p: p['starts'], lambda p: -p['goals'], first_num)
    carded = ranked([p for p in plist if p['yc'] > 0], lambda p: p['yc'])
    intro = ranked([p for p in plist if p['on'] > 0], lambda p: p['on'])
    pairs = sorted(sub_pairs.items(), key=lambda kv: -kv[1])   # stable: first-seen wins ties

    latest_xi = None
    if latest is not None:
        is_home = team_is_target(latest.get('homeTeam'), target)
        xi = [e for e in lineup_by_match[id(latest)] if isinstance(e, dict) and e.get('starter')]
        xi.sort(key=lambda e: e.get('shirtNumber') if isinstance(e.get('shirtNumber'), int) else 99)
        latest_xi = dict(
            home=clean_team(latest.get('homeTeam')), away=clean_team(latest.get('awayTeam')),
            home_short=short_team(latest.get('homeTeam')), away_short=short_team(latest.get('awayTeam')),
            score=(latest['score'].get('home'), latest['score'].get('away')), round=latest.get('round'),
            outcome=results[-1]['outcome'],
            players=[dict(number=e.get('shirtNumber'), name=clean_full_name(e.get('name') or ''),
                          captain=bool(e.get('captain')), gk=bool(e.get('goalkeeper'))) for e in xi])

    def disp(p):
        # lineup shirt numbers win; a pure substitute only has the number implied by who they replaced
        return dict(name=p['full'], number=usual_number(p['numbers'] or p['sub_numbers']))

    return dict(
        team=target, competition=data.get('competition') or '', n=n, summary=summary, results=results, form=form,
        captains=[dict(name=p['full'], n=p['captain']) for p in ranked([p for p in plist if p['captain']], lambda p: p['captain'])],
        keepers=[dict(name=p['full'], n=p['gk']) for p in ranked([p for p in plist if p['gk']], lambda p: p['gk'])],
        goals=[dict(disp(p), goals=p['goals']) for p in goal_rows],
        starters=[dict(disp(p), starts=p['starts'], goals=p['goals'], yc=p['yc']) for p in starters],
        intro=[dict(disp(p), on=p['on'], raw=p.get('raw') or p['full']) for p in intro],
        pairs=[dict(out=dict(disp(players[o])), into=players[i]['full'], n=c) for (o, i), c in pairs],
        carded=[dict(disp(p), yc=p['yc']) for p in carded],
        reds=sum(p['rc'] for p in plist), latest=latest_xi,
    )
