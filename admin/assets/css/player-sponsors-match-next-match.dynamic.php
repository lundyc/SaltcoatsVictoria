<?= matchTemplatePackFontFaceCss($templatePackBrand) ?>

  .next-match-editor { --next-blue: <?= h($templatePackPrimary) ?>; --next-dark: <?= h($templatePackSecondary) ?>; --next-accent: <?= h($templatePackAccent) ?>; --next-text: <?= h($templatePackText) ?>; }
  .next-match-stage { position: relative; width: min(100%, <?= (int)$templatePackCanvasWidth ?>px); aspect-ratio: <?= (int)$templatePackCanvasWidth ?> / <?= (int)$templatePackCanvasHeight ?>; margin-inline: auto; overflow: hidden; background: #111827; }
  .next-match-card {
    position: relative; width: <?= (int)$templatePackCanvasWidth ?>px; height: <?= (int)$templatePackCanvasHeight ?>px; overflow: hidden; color: var(--next-text);
    transform-origin: top left; background: #050505;
    font-family: "<?= h($templatePackBodyFont) ?>", "Roboto", Arial, sans-serif;
  }
  .next-match-card__background { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: <?= h($templatePackBackgroundObjectFit) ?>; display: block; }
  .next-match-card__gradient { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(<?= (int)$nextMatchGradientRgb[0] ?>,<?= (int)$nextMatchGradientRgb[1] ?>,<?= (int)$nextMatchGradientRgb[2] ?>,<?= $nextMatchGradientOpacity * .45 ?>) 0%, rgba(<?= (int)$nextMatchGradientRgb[0] ?>,<?= (int)$nextMatchGradientRgb[1] ?>,<?= (int)$nextMatchGradientRgb[2] ?>,<?= $nextMatchGradientOpacity * .12 ?>) 42%, rgba(<?= (int)$nextMatchGradientRgb[0] ?>,<?= (int)$nextMatchGradientRgb[1] ?>,<?= (int)$nextMatchGradientRgb[2] ?>,<?= $nextMatchGradientOpacity ?>) 100%); }
  .next-match-card__content { position: relative; z-index: 2; height: 100%; padding: 0 72px 64px; display: flex; flex-direction: column; align-items: center; text-align: center; }
  .next-match-card__match-panel { position: relative; margin-top: 365px; width: 560px; min-height: 164px; padding: 32px 38px 22px; display: flex; align-items: center; justify-content: center; gap: 42px; background: rgba(5,5,7,.5); box-shadow: 0 18px 36px rgba(0,0,0,.25); }
  .next-match-card__match-panel-accent { position: absolute; z-index: 1; top: 0; left: 0; width: 100%; height: 7px; display: block; background: linear-gradient(to left, var(--next-accent) 0 50%, var(--next-blue) 50% 100%); }
  .next-match-card__badge { width: 116px; height: 116px; object-fit: contain; filter: drop-shadow(0 10px 18px rgba(0,0,0,.35)); }
  .next-match-card__competition-mark { width: 170px; min-height: 105px; display: flex; align-items: center; justify-content: center; }
  .next-match-card__competition-image { display: block; width: 118px; height: 118px; object-fit: contain; filter: drop-shadow(0 10px 18px rgba(0,0,0,.35)); }
  .next-match-card__title { margin: 38px 0 0; color: var(--next-text); font-family: "<?= h($templatePackHeadingFont) ?>", "Roboto", Arial, sans-serif; font-size: 174px; line-height: .82; letter-spacing: 0; font-weight: 400; text-transform: <?= h($templatePackHeadingTransform) ?>; white-space: nowrap; }
  .next-match-card__details { margin-top: 30px; color: #fff; text-align: center; text-shadow: 0 3px 14px rgba(0,0,0,.85); }
  .next-match-card__venue { margin: 0 0 7px; color: #fff; font-size: 35px; line-height: 1.1; font-weight: 600; }
  .next-match-card__fixture-sponsors { width: min(760px, 100%); margin: 18px auto; display: grid; grid-template-columns: repeat(var(--fixture-sponsor-columns, 1), minmax(0, 1fr)); align-items: stretch; gap: 18px; }
  .next-match-card__fixture-sponsor { min-width: 0; padding: 14px 18px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 9px; background: rgba(5,5,7,.5); text-align: center; }
  .next-match-card__fixture-sponsor-logo { display: block; flex: 0 0 auto; width: auto; max-width: 300px; height: auto; max-height: 300px; object-fit: contain; filter: drop-shadow(0 5px 10px rgba(0,0,0,.4)); }
  .next-match-card__fixture-sponsor-role { display: block; color: rgba(255,255,255,.76); font-size: 15px; line-height: 1.1; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
  .next-match-card__fixture-sponsor-name { display: block; color: #fff; font-size: 21px; line-height: 1.1; font-weight: 700; }
  .next-match-card__date { margin: 0; color: #fff; font-size: 31px; line-height: 1.15; font-weight: 500; }
  .next-match-card__sponsors { margin-top: auto; width: min(860px, 100%); display: grid; grid-template-columns: repeat(var(--main-sponsor-columns, 2), minmax(0, 1fr)); align-items: center; justify-items: center; gap: 24px 38px; min-height: 94px; }
  .next-match-card__sponsor-logo { display: block; width: auto; max-width: 100%; height: auto; max-height: 88px; object-fit: contain; filter: drop-shadow(0 8px 16px rgba(0,0,0,.4)); }
  .next-match-card--pack-five-next-up .next-match-card__fixture-sponsor { height: 100%; }
  .next-match-card--pack-five-next-up .next-match-card__fixture-sponsor-logo { max-height: 210px; }
  .next-match-control { border: 1px solid #e5e7eb; border-radius: .85rem; padding: 1rem; background: #fff; }
  .next-match-caption-toolbar { display: flex; width: 100%; gap: .4rem; padding: .55rem; border: 1px solid #d7dce2; border-bottom: 0; border-radius: .6rem .6rem 0 0; background: #f8fafc; }
  .next-match-caption-toolbar .btn { flex: 1 1 0; min-width: 0; font-weight: 700; }
  #nextMatchCaption { border-radius: 0 0 .6rem .6rem; resize: vertical; }
  .next-match-background-dropzone { display: flex; min-height: 7.5rem; flex-direction: column; align-items: center; justify-content: center; gap: .45rem; padding: 1rem; border: 2px dashed #c8ced6; border-radius: .7rem; background: #f8fafc; color: #5b6472; text-align: center; cursor: pointer; transition: border-color .18s ease, background .18s ease, color .18s ease, opacity .18s ease; }
  .next-match-background-dropzone:hover,
  .next-match-background-dropzone:focus-within,
  .next-match-background-dropzone.is-dragover { border-color: #b72b32; background: #fff6f6; color: #8f1f26; }
  .next-match-background-dropzone.is-uploading,
  .next-match-background-dropzone.is-uploading:hover { border-color: #0d6efd; background: #e7f1ff; color: #084298; pointer-events: none; }
  .next-match-background-dropzone.is-success,
  .next-match-background-dropzone.is-success:hover { border-color: #198754; background: #d1e7dd; color: #0f5132; }
  .next-match-background-dropzone.is-error,
  .next-match-background-dropzone.is-error:hover { border-color: #dc3545; background: #f8d7da; color: #842029; }
  .next-match-background-dropzone i,
  .next-match-background-dropzone .svg-inline--fa { font-size: 1.55rem; }
  .next-match-channel-list { display: flex; align-items: stretch; width: 100%; gap: .55rem; }
  .next-match-channel-option { position: relative; display: inline-flex; flex: 1 1 0; align-items: center; justify-content: center; min-width: 0; height: 2.75rem; padding: 0 .75rem; border: 1px solid #d7dce2; border-radius: .5rem; background: #fff; cursor: pointer; }
  .next-match-channel-option { transition: color .18s ease, border-color .18s ease, background .18s ease, box-shadow .18s ease; }
  .next-match-channel-option--facebook:hover,
  .next-match-channel-option--facebook:has(input:checked) { border-color: #1877f2; background: #1877f2; box-shadow: 0 5px 14px rgba(24,119,242,.24); }
  .next-match-channel-option--instagram:hover,
  .next-match-channel-option--instagram:has(input:checked) { border-color: #c13584; background: linear-gradient(135deg, #f58529, #dd2a7b 52%, #8134af); box-shadow: 0 5px 14px rgba(193,53,132,.24); }
  .next-match-channel-option--x:hover,
  .next-match-channel-option--x:has(input:checked) { border-color: #000; background: #000; box-shadow: 0 5px 14px rgba(0,0,0,.22); }
  .next-match-channel-option--download:hover { border-color: #198754; background: #198754; box-shadow: 0 5px 14px rgba(25,135,84,.24); }
  .next-match-channel-option:focus-within { outline: 3px solid rgba(183,43,50,.22); outline-offset: 2px; }
  .next-match-channel-option input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
  .next-match-channel-option i { font-size: 1.35rem; line-height: 1; text-align: center; }
  .next-match-channel-option:hover i,
  .next-match-channel-option:has(input:checked) i,
  .next-match-channel-option:hover .svg-inline--fa,
  .next-match-channel-option:has(input:checked) .svg-inline--fa { color: #fff !important; fill: currentColor; }
  .next-match-status-list { display: grid; gap: .55rem; }
  .next-match-status-list .alert { padding: .7rem .85rem; }
