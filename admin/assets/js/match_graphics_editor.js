(() => {
  if (!window.EditorConfig) return;

  const EC = window.EditorConfig;
  const canvasWrap = document.getElementById('canvas-wrap');
  const baseLayer = document.getElementById('base-layer');
  const dayEl = document.getElementById('match-day-box');
  const ballEl = document.getElementById('match-ball-box');
  const fixtureSelect = document.getElementById('fixtureSelect');
  const saveBtn = document.getElementById('saveBtn');
  const resetBtn = document.getElementById('resetBtn');
  const downloadBtn = document.getElementById('downloadBtn');

  const initial = JSON.parse(JSON.stringify(EC));

  function createBox(el) {
    if (!el) return;

    const width = parseFloat(el.style.width) || 1;
    const height = parseFloat(el.style.height) || 1;
    const aspectRatio = width / height;

    interact(el).draggable({
      listeners: {
        move(event) {
          const style = window.getComputedStyle(el);
          const matrix = new DOMMatrixReadOnly(style.transform);
          const nx = (matrix.m41 || 0) + event.dx;
          const ny = (matrix.m42 || 0) + event.dy;
          el.style.transform = `translate(${nx}px, ${ny}px)`;
        },
        end() {
          const rect = el.getBoundingClientRect();
          const parent = el.parentElement.getBoundingClientRect();
          el.style.left = `${rect.left - parent.left}px`;
          el.style.top = `${rect.top - parent.top}px`;
          el.style.transform = 'translate(0,0)';
        }
      },
      modifiers: [
        interact.modifiers.restrictRect({
          restriction: canvasWrap,
          endOnly: true
        })
      ]
    });

    interact(el).resizable({
      edges: { left: true, right: true, top: true, bottom: true },
      modifiers: [
        interact.modifiers.aspectRatio({
          ratio: aspectRatio,
          modifiers: [
            interact.modifiers.restrictEdges({ outer: canvasWrap }),
            interact.modifiers.restrictSize({
              min: { width: 220, height: 72 },
              max: { width: EC.canvas.width, height: EC.canvas.height }
            })
          ]
        })
      ],
      listeners: {
        move(event) {
          const { width, height } = event.rect;
          el.style.width = `${width}px`;
          el.style.height = `${height}px`;
          const style = window.getComputedStyle(el);
          const matrix = new DOMMatrixReadOnly(style.transform);
          const nx = (matrix.m41 || 0) + event.deltaRect.left;
          const ny = (matrix.m42 || 0) + event.deltaRect.top;
          el.style.transform = `translate(${nx}px, ${ny}px)`;
        },
        end() {
          const rect = el.getBoundingClientRect();
          const parent = el.parentElement.getBoundingClientRect();
          el.style.left = `${rect.left - parent.left}px`;
          el.style.top = `${rect.top - parent.top}px`;
          el.style.transform = 'translate(0,0)';
        }
      }
    });
  }

  function readRect(el) {
    if (!el) return null;
    const rect = el.getBoundingClientRect();
    const parent = el.parentElement.getBoundingClientRect();
    return {
      x: Math.round(rect.left - parent.left),
      y: Math.round(rect.top - parent.top),
      w: Math.round(rect.width),
      h: Math.round(rect.height)
    };
  }

  function currentPayload() {
    const payload = {
      season_id: EC.seasonId,
      fixture_id: EC.fixtureId
    };
    const day = readRect(dayEl);
    if (day) Object.assign(payload, {
      match_day_x: day.x,
      match_day_y: day.y,
      match_day_width: day.w,
      match_day_height: day.h
    });
    const ball = readRect(ballEl);
    if (ball) Object.assign(payload, {
      match_ball_x: ball.x,
      match_ball_y: ball.y,
      match_ball_width: ball.w,
      match_ball_height: ball.h
    });
    return payload;
  }

  async function saveLayout() {
    const fd = new FormData();
    Object.entries(currentPayload()).forEach(([k, v]) => fd.append(k, v));
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');

    try {
      const res = await fetch(EC.endpoints.save, { method: 'POST', body: fd });
      const json = await res.json();
      if (!json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'Unable to save layout');
      }

      const stamp = Date.now().toString();
      if (downloadBtn) {
        const url = new URL(EC.endpoints.render, window.location.origin);
        url.searchParams.set('season_id', EC.seasonId);
        url.searchParams.set('fixture_id', EC.fixtureId);
        url.searchParams.set('t', stamp);
        downloadBtn.href = url.toString();
      }
      if (baseLayer) {
        const url = new URL(EC.endpoints.render, window.location.origin);
        url.searchParams.set('season_id', EC.seasonId);
        url.searchParams.set('fixture_id', EC.fixtureId);
        url.searchParams.set('edit_base', '1');
        url.searchParams.set('t', stamp);
        baseLayer.style.backgroundImage = `url("${url.toString()}")`;
      }
      showToast('Layout saved.', 'success');
    } catch (err) {
      showToast(err.message || 'Save failed.', 'danger');
    }
  }

  function resetLayout() {
    if (!initial.layout) return;
    const setRect = (el, cfg) => {
      if (!el || !cfg) return;
      el.style.left = `${cfg.x}px`;
      el.style.top = `${cfg.y}px`;
      el.style.width = `${cfg.w}px`;
      el.style.height = `${cfg.h}px`;
      el.style.transform = 'translate(0,0)';
    };
    setRect(dayEl, initial.layout.match_day);
    setRect(ballEl, initial.layout.match_ball);
  }

  function hookFixtureSelect() {
    if (!fixtureSelect) return;
    fixtureSelect.addEventListener('change', () => {
      const url = new URL(window.location.href);
      url.searchParams.set('fixture_id', fixtureSelect.value);
      window.location.href = url.toString();
    });
  }

  function showToast(message, tone) {
    const existing = document.querySelector('.match-graphics-toast');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.className = `alert alert-${tone} match-graphics-toast position-fixed bottom-0 end-0 m-3 shadow`;
    el.style.zIndex = '1080';
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3000);
  }

  createBox(dayEl);
  createBox(ballEl);
  hookFixtureSelect();

  if (saveBtn) saveBtn.addEventListener('click', saveLayout);
  if (resetBtn) resetBtn.addEventListener('click', resetLayout);
})();
