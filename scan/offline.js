/*
 * Ticket Scanner: offline engine.
 *
 * While the phone has signal it keeps a saved list of the tickets for today's match or event (hashed, so a lost
 * phone does not hold usable QR codes). When the signal drops, scans are checked against that list on the phone,
 * and each admission is queued. When the signal returns the queue is sent to the server, which judges every
 * queued scan with the same rules as a live scan and keeps the time it really happened. A ticket let in at two
 * gates while offline is admitted by whichever syncs first; the second is flagged for review, never lost.
 *
 * Exposes window.ScanOffline. Everything is stored in localStorage and survives a reload.
 */
(() => {
    'use strict';

    const LIST_KEY = 'ticket-scanner-gate-list';
    const QUEUE_KEY = 'ticket-scanner-queue';
    const DEVICE_KEY = 'ticket-scanner-device';
    const GATE_KEY = 'ticket-scanner-gate';
    const LIST_REFRESH_MS = 90000;
    const HEARTBEAT_MS = 20000;
    const SYNC_MS = 15000;
    const MAX_LIST_AGE_MS = 12 * 3600 * 1000;

    const enc = new TextEncoder();
    let cfg = null;
    let list = null;      // { kind, id, salt, iterations, entries, byToken, byManual, savedAt, count0, capacity }
    let queue = [];
    let online = navigator.onLine !== false;
    let lastOnlineCheck = 0;
    let syncing = false;
    let lastSyncMessage = '';
    let listener = () => {};

    const read = (key, fallback) => { try { const v = localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch (e) { return fallback; } };
    const write = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* storage full or blocked */ } };
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : (Date.now().toString(36) + Math.random().toString(36).slice(2, 12))).slice(0, 32);
    const toHex = (buf) => Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('');

    const sha = async (text) => toHex(await crypto.subtle.digest('SHA-256', enc.encode(text)));
    const pbkdf2 = async (code, salt, iterations) => {
        const key = await crypto.subtle.importKey('raw', enc.encode(code), 'PBKDF2', false, ['deriveBits']);
        return toHex(await crypto.subtle.deriveBits({ name: 'PBKDF2', salt: enc.encode(salt), iterations, hash: 'SHA-256' }, key, 128));
    };

    const deviceId = () => {
        let id = read(DEVICE_KEY, '');
        if (!id) { id = 'D' + uuid().slice(0, 10); write(DEVICE_KEY, id); }
        return id;
    };
    // One fixed entry point: matches use "Gate", events use "Entry". Gate naming was removed (single scanner for now).
    const getGate = () => (cfg && cfg.kind === 'event' ? 'Entry' : 'Gate');
    const setGate = (name) => { write(GATE_KEY, String(name || '').slice(0, 40)); notify(); };

    const notify = () => {
        const age = list ? Math.round((Date.now() - list.savedAt) / 60000) : null;
        listener({ online, listReady: !!list, listAgeMin: age, savedAt: list ? list.savedAt : 0, queued: queue.length, syncing, message: lastSyncMessage, gate: getGate() });
    };

    /* ---------------------------------------------------------------- the saved list */

    const indexList = (l) => {
        l.byToken = new Map();
        l.byManual = new Map();
        l.entries.forEach((e, i) => { l.byToken.set(e.t, i); if (e.m) l.byManual.set(e.m, i); });
        return l;
    };

    const loadSaved = () => {
        const s = read(LIST_KEY, null);
        if (s && s.kind === cfg.kind && Number(s.id) === Number(cfg.targetId) && Date.now() - s.savedAt < MAX_LIST_AGE_MS) {
            list = indexList(s);
        }
        queue = read(QUEUE_KEY, []).filter((q) => q && q.id);
    };

    // Admissions made offline but not yet synced count as already admitted, so the same ticket cannot be let in twice.
    const applyQueue = () => {
        if (!list) return;
        queue.forEach((q) => {
            if (q.kind !== list.kind || Number(q.target) !== Number(list.id) || !q.entry) return;
            const e = list.entries[list.byToken.get(q.entry)];
            if (e && e.i < e.a) e.i += 1;
        });
    };

    const refreshList = async () => {
        if (!cfg || !navigator.onLine) return false;
        try {
            const res = await fetch(`/admin/scan_gate_list.php?kind=${cfg.kind}&id=${cfg.targetId}`, { credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) { if (res.status !== 403) markOffline(false); return false; }
            const data = await res.json();
            if (!data || !data.ok) return false;
            markOnline();
            list = indexList({ kind: data.kind, id: data.id, salt: data.salt, iterations: data.iterations, entries: data.entries, savedAt: Date.now(), count0: data.count });
            applyQueue();
            write(LIST_KEY, { kind: list.kind, id: list.id, salt: list.salt, iterations: list.iterations, entries: list.entries, savedAt: list.savedAt });
            notify();
            return true;
        } catch (e) {
            markOffline(true);
            return false;
        }
    };

    /* ---------------------------------------------------------------- connection state */

    const markOnline = () => { if (!online) { online = true; notify(); syncSoon(); } lastOnlineCheck = Date.now(); };
    const markOffline = (changeState) => { lastOnlineCheck = Date.now(); if (changeState !== false && online) { online = false; notify(); } };

    /* ---------------------------------------------------------------- checking a scan on the phone */

    const extractToken = (value) => {
        const v = String(value || '').trim();
        const m = v.match(/(?:ticket|token)=([a-f0-9]{20,80})/i);
        if (m) return m[1].toLowerCase();
        return /^[a-f0-9]{20,80}$/i.test(v) ? v.toLowerCase() : '';
    };

    const kindLabel = (k) => (k === 'event' ? 'Event Ticket' : (k === 'season' ? 'Season Ticket' : 'Match Ticket'));

    const result = (ok, status, message, extra = {}) => Object.assign({ ok, valid_ticket: ok, status, message, offline: true, holder: null, scan: null }, extra);

    /** Judge a scan from the saved list. Returns a reply shaped like the server's. */
    const check = async (value) => {
        if (!list) return null;
        const v = String(value || '').trim();
        if (/access=/i.test(v) && !/(ticket|token)=/i.test(v)) {
            return result(false, 'individual_required', 'Please scan an individual ticket QR code.');
        }
        let idx;
        let entryHash = '';
        const token = extractToken(v);
        if (token) {
            entryHash = (await sha(list.salt + token)).slice(0, 32);
            idx = list.byToken.get(entryHash);
        } else {
            const code = v.toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (code.length === 7) {
                const h = (await pbkdf2(code, list.salt, list.iterations)).slice(0, 32);
                idx = list.byManual.get(h);
                if (idx !== undefined) entryHash = list.entries[idx].t;
            }
        }
        if (idx === undefined) {
            return result(false, 'not_on_list', 'Not on the saved list. This phone has no signal, so it cannot check further.');
        }
        const e = list.entries[idx];
        const holder = { name: e.n || kindLabel(e.k), ticket_kind: kindLabel(e.k), ticket: e.l, order_id: 0 };
        if (e.s !== 'valid') {
            return result(false, 'not_active', 'This ticket has been cancelled.', { holder });
        }
        if (e.i >= e.a) {
            return result(false, 'already_scanned', e.a > 1 ? `All ${e.a} people on this ticket are already in.` : 'This ticket has already been scanned.', { holder });
        }
        e.i += 1;
        const out = result(true, 'checked_in', e.a > 1 ? `Checked in: ${e.i} of ${e.a} people.` : 'Ticket checked in.', { holder, progress: e.a > 1 ? `${e.i} of ${e.a} in` : null });
        out.entryHash = entryHash;
        return out;
    };

    /** Offline scan: check it, and queue the admission so the server learns about it. */
    const scanOffline = async (value, options = {}) => {
        const out = await check(value);
        if (!out) return null;
        if (out.ok) {
            const item = { id: uuid(), kind: cfg.kind, target: cfg.targetId, value: String(value).trim(), ts: Date.now(), gate: getGate(), device: deviceId(), entry: out.entryHash };
            queue.push(item);
            write(QUEUE_KEY, queue);
            write(LIST_KEY, { kind: list.kind, id: list.id, salt: list.salt, iterations: list.iterations, entries: list.entries, savedAt: list.savedAt });
            out.count = (list.entries.reduce((n, e) => n + e.i, 0));
            notify();
        }
        out.scan = { accepted: out.ok, status: out.status, message: out.message, holder_name: out.holder ? out.holder.name : '', ticket_kind: out.holder ? out.holder.ticket_kind : '', ticket_label: out.holder ? out.holder.ticket : '', scanned_at: '' };
        return out;
    };

    /* ---------------------------------------------------------------- sync */

    let syncTimer = 0;
    const syncSoon = () => { clearTimeout(syncTimer); syncTimer = setTimeout(sync, 1500); };

    const sync = async () => {
        if (syncing || !queue.length || !cfg || !navigator.onLine) return;
        syncing = true;
        notify();
        try {
            const batch = queue.slice(0, 100);
            const body = new URLSearchParams({ csrf_token: cfg.csrf, items: JSON.stringify(batch.map(({ entry, ...rest }) => rest)) });
            const res = await fetch('/admin/scan_sync.php', { method: 'POST', credentials: 'same-origin', body });
            if (res.status === 401 || res.status === 419) {
                lastSyncMessage = res.status === 401 ? 'Log in again to send the saved scans. They are kept on this phone.' : 'Reload this page while online to send the saved scans. They are kept on this phone.';
                return;
            }
            const data = await res.json();
            if (!data || !data.ok) return;
            markOnline();
            const done = new Set(data.results.filter((r) => !r.retry).map((r) => r.id));
            const dup = data.results.filter((r) => r.status === 'already_scanned').length;
            const failed = data.results.filter((r) => !r.retry && !r.accepted && r.status !== 'already_scanned').length;
            queue = queue.filter((q) => !done.has(q.id));
            write(QUEUE_KEY, queue);
            lastSyncMessage = `Sent ${done.size} saved scan${done.size === 1 ? '' : 's'}`
                + (dup ? `. ${dup} had already been admitted elsewhere (flagged for review)` : '')
                + (failed ? `. ${failed} could not be added` : '') + '.';
            await refreshList();
            if (queue.length) syncSoon();
        } catch (e) {
            markOffline(true);
        } finally {
            syncing = false;
            notify();
        }
    };

    /* ---------------------------------------------------------------- heartbeat for the door dashboard */

    const heartbeat = async () => {
        if (!cfg) return;
        try {
            const body = new URLSearchParams({ csrf_token: cfg.csrf, device: deviceId(), gate: getGate(), kind: cfg.kind, target: String(cfg.targetId), queued: String(queue.length), online: '1' });
            const res = await fetch('/admin/scan_heartbeat.php', { method: 'POST', credentials: 'same-origin', body });
            if (res.ok) { markOnline(); } else if (res.status !== 419 && res.status !== 401) { markOffline(true); }
        } catch (e) {
            markOffline(true);
        }
    };

    /* ---------------------------------------------------------------- public API */

    const init = async (config) => {
        cfg = config;
        listener = config.onStatus || listener;
        loadSaved();
        notify();
        window.addEventListener('online', () => { online = true; notify(); refreshList(); syncSoon(); heartbeat(); });
        window.addEventListener('offline', () => { online = false; notify(); });
        await refreshList();
        setInterval(() => { if (navigator.onLine) refreshList(); }, LIST_REFRESH_MS);
        setInterval(heartbeat, HEARTBEAT_MS);
        setInterval(() => { if (queue.length) sync(); }, SYNC_MS);
        heartbeat();
        if (queue.length) syncSoon();
    };

    window.ScanOffline = {
        init,
        /** True when the phone believes it has no usable connection and a saved list is ready. */
        shouldUseOffline: () => !!list && (!online || navigator.onLine === false),
        ready: () => !!list,
        scanOffline,
        /** Call when a live request failed (no signal or timeout): returns the offline reply, or null if there is no saved list. */
        async fallback(value) { markOffline(true); return scanOffline(value); },
        markOnline,
        sync,
        getGate,
        setGate,
        deviceId,
        /** Totals from the saved list: valid tickets and how many are already in, split into tickets and season tickets. */
        counts: () => {
            if (!list || !Array.isArray(list.entries)) return null;
            const out = { tickets: { in: 0, total: 0 }, season: { in: 0, total: 0 } };
            list.entries.forEach((e) => {
                if (e.s !== 'valid') return;
                const b = e.k === 'season' ? out.season : out.tickets;
                const a = Math.max(1, Number(e.a) || 1);
                b.total += a;
                b.in += Math.min(a, Number(e.i) || 0);
            });
            return out;
        },
        status: () => ({ online, listReady: !!list, queued: queue.length, savedAt: list ? list.savedAt : 0, gate: getGate() }),
    };
})();
