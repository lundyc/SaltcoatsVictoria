(() => {
  const progress = document.getElementById('pdfProgress');
  let busy = false;
  const show = message => { progress.classList.remove('d-none'); progress.textContent = message; };
  async function post(action, fields = {}) {
    const body = new FormData(document.getElementById('pdfToken'));
    body.set('action', action); body.set('ajax', '1');
    Object.entries(fields).forEach(([key, value]) => body.set(key, value));
    const response = await fetch('/admin/pdf_importer.php', { method: 'POST', body, credentials: 'same-origin' });
    let data;
    try { data = await response.json(); } catch { throw new Error('Session expired or the server did not respond. Reload this page; completed work is saved.'); }
    if (!data.ok) throw new Error(data.message);
    return data;
  }
  async function run(task) {
    if (busy) return;
    busy = true;
    try { await task(); } catch (error) { show(error.message); } finally { busy = false; }
  }
  document.getElementById('processQueue')?.addEventListener('click', () => run(async () => {
    let count = 0;
    while (true) {
      show(`Reading PDFs… ${count} done. You can leave this page — a background job also reads them.`);
      const data = await post('process');
      if (data.message === 'Nothing left to read.') break;
      count++;
    }
    location.reload();
  }));
  document.getElementById('pdfUploads')?.addEventListener('change', event => run(async () => {
    const files = [...event.target.files];
    const failures = [];
    for (let i = 0; i < files.length; i++) {
      show(`Uploading ${i + 1} of ${files.length}: ${files[i].name}`);
      try { await post('upload', { pdf: files[i] }); } catch (error) { failures.push(`${files[i].name}: ${error.message}`); }
    }
    if (failures.length) show(`Uploads finished with issues: ${failures.join('; ')}. Refresh to see the queued reports.`);
    else location.href = '/admin/pdf_importer.php';
  }));
  document.getElementById('selectReady')?.addEventListener('change', event => {
    document.querySelectorAll('.readyReport').forEach(input => { input.checked = event.target.checked; });
  });
  document.getElementById('importSelected')?.addEventListener('click', () => run(async () => {
    const selected = [...document.querySelectorAll('.readyReport:checked')];
    if (!selected.length) { show('Tick at least one ready report first.'); return; }
    const failures = [];
    for (let i = 0; i < selected.length; i++) {
      show(`Importing report ${i + 1} of ${selected.length}…`);
      try { await post('apply', { id: selected[i].value }); selected[i].disabled = true; selected[i].checked = false; }
      catch (error) { failures.push(`Report #${selected[i].value}: ${error.message}`); }
    }
    if (failures.length) show(`Finished. ${failures.join('; ')}. The imports that worked are saved; the rest can be opened and tried again.`);
    else location.reload();
  }));
  // Step 1 — filter the full fixture list (season + free text), paginate it, and "jump to ID".
  const fxList = document.getElementById('fxList');
  if (fxList) {
    const PAGE = 40;
    const rows = [...fxList.querySelectorAll('.wiz-fxrow[data-text]')];
    const groups = [...fxList.querySelectorAll('.wiz-fxgrp')];
    const pager = document.getElementById('fxPager');
    let page = 0;

    // click anywhere on a fixture row to select it
    fxList.querySelectorAll('tr.wiz-fxrow').forEach(tr => tr.addEventListener('click', e => {
      if (e.target.closest('a,button,input')) return;
      const radio = tr.querySelector('input[type=radio]');
      if (radio) radio.checked = true;
    }));
    const seasonSel = document.getElementById('fxSeason');
    const filter = document.getElementById('fxFilter');

    const drawPager = (total, pages) => {
      if (!pager) return;
      if (pages <= 1) { pager.hidden = true; pager.innerHTML = ''; return; }
      pager.hidden = false;
      pager.innerHTML = '';
      const btn = (label, target, disabled) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-secondary btn-sm';
        b.textContent = label;
        b.disabled = disabled;
        if (!disabled) b.addEventListener('click', () => { page = target; apply(); });
        return b;
      };
      const info = document.createElement('span');
      info.className = 'fx-pager__info';
      info.textContent = `Page ${page + 1} of ${pages} · ${total} fixtures`;
      pager.append(btn('‹ Prev', page - 1, page === 0), info, btn('Next ›', page + 1, page >= pages - 1));
    };

    const apply = () => {
      const q = (filter?.value || '').trim().toLowerCase();
      const season = seasonSel?.value || '';
      const matched = rows.filter(r => (!season || r.dataset.season === season) && (!q || r.dataset.text.includes(q)));
      // Paginate only a long unfiltered-ish list; a narrowed one shows in full with its season headers.
      const paginate = !season && matched.length > PAGE;
      const pages = paginate ? Math.ceil(matched.length / PAGE) : 1;
      if (page >= pages) page = pages - 1;
      if (page < 0) page = 0;
      const from = paginate ? page * PAGE : 0;
      const to = paginate ? from + PAGE : matched.length;
      const shown = new Set(matched.slice(from, to));
      rows.forEach(r => r.classList.toggle('is-hidden', !shown.has(r)));
      groups.forEach(g => {
        if (paginate) { g.classList.add('is-hidden'); return; }
        let n = g.nextElementSibling;
        let any = false;
        while (n && n.classList.contains('wiz-fxrow')) { if (!n.classList.contains('is-hidden')) any = true; n = n.nextElementSibling; }
        g.classList.toggle('is-hidden', !any);
      });
      drawPager(matched.length, pages);
    };

    seasonSel?.addEventListener('change', () => { page = 0; apply(); });
    filter?.addEventListener('input', () => { page = 0; apply(); });

    // Reveal a specific row (used by "jump to ID" and "use best match"): clear
    // filters, move to the page that holds it, then select + flash it.
    const revealRow = row => {
      if (!row) return;
      if (seasonSel) seasonSel.value = '';
      if (filter) filter.value = '';
      page = Math.floor(rows.indexOf(row) / PAGE);
      apply();
      row.scrollIntoView({ block: 'center' });
      row.querySelectorAll('td').forEach(td => { td.style.transition = 'background .6s'; td.style.background = 'rgba(224,180,42,.35)'; });
      setTimeout(() => row.querySelectorAll('td').forEach(td => { td.style.background = ''; }), 900);
    };

    const useBest = document.querySelector('[data-use-best]');
    useBest?.addEventListener('click', () => {
      const best = fxList.querySelector('input[name="fixture_id"][data-best]');
      if (best) { best.checked = true; revealRow(best.closest('.wiz-fxrow')); }
    });
    if (useBest?.dataset.autoAdvance === '1') {
      setTimeout(() => useBest.click(), 250);
    }
    document.getElementById('findFixture')?.addEventListener('click', () => {
      const v = Number(document.getElementById('manualFixture').value);
      const hit = fxList.querySelector(`input[name="fixture_id"][value="${v}"]`);
      if (hit) { hit.checked = true; revealRow(hit.closest('.wiz-fxrow')); }
      else if (v > 0) { alert('No fixture #' + v + ' in the list.'); }
    });

    apply();
  }

  // Step 3 — show the player-linking block only when "Use the PDF line-ups" is chosen,
  // and don't let the wizard move on until every Saltcoats name is linked.
  const xiForm = document.getElementById('xiForm');
  if (xiForm) {
    const block = document.getElementById('linkBlock');
    const counter = document.getElementById('playerCount');
    const selects = [...xiForm.querySelectorAll('.pdf-player__sel')];
    const nextBtn = xiForm.querySelector('button[value="next"]');
    const usePdf = () => xiForm.querySelector('input[name="source"]:checked')?.value !== 'web';
    const refresh = () => {
      const on = usePdf();
      if (block) block.hidden = !on;
      const linked = selects.filter(s => s.value !== '').length;
      if (counter) counter.textContent = `${linked} of ${selects.length} linked`;
      const missing = on ? selects.length - linked : 0;
      if (nextBtn) {
        nextBtn.disabled = missing > 0;
        nextBtn.title = missing > 0 ? `Link ${missing} more player${missing === 1 ? '' : 's'} first` : '';
      }
    };
    xiForm.querySelectorAll('input[name="source"]').forEach(r => r.addEventListener('change', refresh));
    selects.forEach(s => s.addEventListener('change', refresh));
    xiForm.querySelector('details')?.addEventListener('toggle', refresh);
    refresh();
  }

  // Step 6 — one click imports; guard against a double submit.
  const importForm = document.getElementById('importForm');
  if (importForm) {
    importForm.addEventListener('submit', () => {
      const btn = document.getElementById('importBtn');
      if (btn) { btn.disabled = true; btn.textContent = 'Importing…'; }
    });
  }
})();
