<?= matchTemplatePackFontFaceCss($templatePackBrand) ?>

  .starting11-graphic-page {
    --s11-maroon: <?= h($templatePackPrimary) ?>;
    --s11-maroon-dark: <?= h($templatePackSecondary) ?>;
    --s11-gold: <?= h($templatePackAccent) ?>;
    --s11-gold-dark: #d9a51e;
    --s11-text: <?= h($templatePackText) ?>;
    --s11-display-font: "<?= h($templatePackHeadingFont) ?>", Georgia, serif;
    --s11-body-font: "<?= h($templatePackBodyFont) ?>", Arial, sans-serif;
  }

  .starting11-graphic-preview-wrap {
    display: flex;
    justify-content: center;
    overflow-x: auto;
    padding-bottom: 1rem;
  }

  .starting11-graphic-card {
    --s11-maroon: <?= h($templatePackPrimary) ?>;
    --s11-maroon-dark: <?= h($templatePackSecondary) ?>;
    --s11-gold: <?= h($templatePackAccent) ?>;
    --s11-gold-dark: #d9a51e;
    --s11-text: <?= h($templatePackText) ?>;
    --s11-display-font: "<?= h($templatePackHeadingFont) ?>", Georgia, serif;
    --s11-body-font: "<?= h($templatePackBodyFont) ?>", Arial, sans-serif;
    position: relative;
    width: <?= (int)$templatePackCanvasWidth ?>px;
    height: <?= (int)$templatePackCanvasHeight ?>px;
    overflow: hidden;
    background:
      linear-gradient(180deg, rgba(65, 51, 46, 0.52) 0%, rgba(20, 20, 20, 0.62) 100%),
      radial-gradient(circle at top, rgba(255, 255, 255, 0.12), transparent 36%),
      linear-gradient(160deg, #6c625d 0%, #3b3532 46%, #201d1b 100%);
    background-position: center;
    background-repeat: no-repeat;
    background-size: <?= h($templatePackBackgroundSize) ?>;
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
    isolation: isolate;
  }

  <?php if ($templatePackGradientStrength > 0): ?>
  .starting11-graphic-card__backdrop {
    background: linear-gradient(
      180deg,
      <?= h($templatePackGradientColour) ?>00 0%,
      <?= h($templatePackGradientColour) ?><?= strtoupper(str_pad(dechex((int)round(255 * $templatePackGradientStrength / 100)), 2, '0', STR_PAD_LEFT)) ?> 100%
    ) !important;
  }
  <?php endif; ?>

  .starting11-graphic-card--export,
  .starting11-graphic-card--export * {
    animation: none !important;
    transition: none !important;
  }

  .starting11-graphic-card--pack-layout .starting11-graphic-card__lineup .starting11-graphic-card__player,
  .starting11-graphic-card--pack-layout .starting11-graphic-card__subs-row {
    font-size: var(--pack-element-font-size, inherit);
    color: inherit;
  }

  .starting11-graphic-card--template-pack {
    font-family: var(--s11-body-font);
    color: var(--s11-text);
  }

  .starting11-graphic-card--template-pack::after {
    content: "";
    position: absolute;
    z-index: 4;
    inset: 0;
    background:
      radial-gradient(ellipse at 50% 44%, transparent 12%, rgba(0, 0, 0, 0.08) 46%, rgba(0, 0, 0, 0.68) 100%),
      linear-gradient(90deg, rgba(0, 0, 0, 0.05), rgba(0, 0, 0, 0.18) 48%, rgba(0, 0, 0, 0.48));
    pointer-events: none;
  }

  .starting11-graphic-card--template-pack .starting11-graphic-card__backdrop {
    z-index: 2;
    background: <?= h($templatePackGradientColour) ?> !important;
    opacity: <?= number_format($templatePackGradientStrength / 100, 2, '.', '') ?>;
  }

  .starting11-graphic-card--template-pack .starting11-graphic-card__pattern {
    z-index: 3;
    display: block;
    opacity: 0.18;
    background-image:
      radial-gradient(rgba(255, 255, 255, 0.38) 0.6px, transparent 0.7px),
      radial-gradient(rgba(255, 255, 255, 0.16) 0.5px, transparent 0.6px);
    background-position: 0 0, 9px 11px;
    background-size: 13px 13px, 17px 17px;
    mix-blend-mode: soft-light;
    mask-image: none;
  }

  .starting11-template-element {
    z-index: 7;
    display: flex;
    box-sizing: border-box;
    align-items: center;
    overflow: hidden;
    white-space: pre-line;
    font-weight: 650;
    line-height: 1.12;
    text-shadow: 0 3px 18px rgba(0, 0, 0, 0.9);
  }

  .starting11-template-headline {
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--s11-body-font);
    font-weight: 450;
    letter-spacing: 0.015em;
    line-height: 1.12;
    text-transform: uppercase;
    white-space: nowrap;
  }

  .starting11-template-badge {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .starting11-template-badge img,
  .starting11-template-competition img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: contain;
    filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.28));
  }

  .starting11-template-lineup {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    justify-content: center;
    gap: 1px;
    overflow: visible;
    font-family: var(--s11-display-font);
    font-weight: 500;
    line-height: 0.98;
    letter-spacing: -0.025em;
    text-transform: uppercase;
  }

  .starting11-template-player {
    display: flex;
    align-items: baseline;
    justify-content: center;
    min-height: 64px;
    white-space: nowrap;
  }

  .starting11-template-player__number {
    width: 54px;
    margin-right: 8px;
    color: #d9c178;
    font-family: var(--s11-body-font);
    font-size: 0.48em;
    font-weight: 700;
    line-height: 1;
    letter-spacing: 0;
    text-align: right;
  }

  .starting11-template-player__name {
    display: inline-block;
    min-width: 0;
    font: inherit;
  }

  .starting11-template-player__captain {
    display: inline-grid;
    width: 30px;
    height: 30px;
    margin-left: 10px;
    place-items: center;
    border: 2px solid currentColor;
    border-radius: 50%;
    color: #d9c178;
    font-family: var(--s11-body-font);
    font-size: 15px;
    font-weight: 600;
    font-style: normal;
    line-height: 1;
    transform: translateY(-10px);
  }

  .starting11-template-subs {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    font-family: var(--s11-body-font);
    line-height: 1.18;
    text-transform: uppercase;
  }

  .starting11-template-subs strong {
    margin-bottom: 7px;
    color: #f5f1e9;
    font-size: 0.7em;
    letter-spacing: 0.12em;
  }

  .starting11-template-subs span {
    text-align: center;
  }

  .starting11-template-competition {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .starting11-template-sponsors {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 28px;
    padding: 4px 8px;
    background: none;
  }

  .starting11-template-sponsors img {
    display: block;
    width: auto;
    min-width: 0;
    max-width: 30%;
    height: 100%;
    object-fit: contain;
    filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.32));
  }

  .starting11-template-sponsors__name {
    max-width: 30%;
    color: inherit;
    font-size: inherit;
    font-weight: 700;
    line-height: 1.05;
    text-align: center;
  }

  .starting11-template-matchday-sponsors.starting11-template-sponsors {
    gap: 40px;
  }

  .starting11-template-matchday-sponsors.starting11-template-sponsors img {
    width: 145px;
    height: 145px;
    max-width: 145px;
    flex: 0 0 auto;
  }

  .starting11-graphic-card__backdrop,
  .starting11-graphic-card__pattern {
    position: absolute;
    inset: 0;
    pointer-events: none;
  }

  .starting11-graphic-card__backdrop {
    background:
      linear-gradient(180deg, rgba(41, 33, 30, 0.34) 0%, rgba(15, 15, 15, 0.36) 100%),
      linear-gradient(90deg, rgba(0, 0, 0, 0.20), rgba(0, 0, 0, 0.05) 44%, rgba(0, 0, 0, 0.38));
    z-index: 0;
  }

  .starting11-graphic-card__pattern {
    background-size: 78px 78px;
    background-position: 18px 0;
    opacity: 0.95;
    z-index: 1;
    mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.9), rgba(0, 0, 0, 0.2));
  }

  .starting11-graphic-card__content {
    position: relative;
    z-index: 2;
    display: grid;
    justify-items: center;
    align-content: start;
    grid-template-rows: auto auto auto 1fr auto;
    height: 100%;
    color: #fff;
    text-align: center;
  }

  .starting11-graphic-card__content--grid {
    grid-template-rows: 1fr;
  }

  .starting11-graphic-card__header {
    display: grid;
    gap: 0.4rem;
    justify-items: center;
  }

  .starting11-graphic-card__title {
    display: flex;
    align-items: flex-end;
    gap: 0.8rem;
    line-height: 0.9;
    text-transform: uppercase;
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__title-main {
    color: #fff;
    font-size: 7.4rem;
    font-weight: 900;
    letter-spacing: -0.08em;
    text-shadow: 0 6px 16px rgba(0, 0, 0, 0.22);
  }

  .starting11-graphic-card__title-accent {
    color: var(--s11-gold);
    font-size: 6.6rem;
    font-weight: 900;
    letter-spacing: -0.08em;
    text-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
  }

  .starting11-graphic-card__meta {
    display: flex;
    align-items: center;
    gap: 1.3rem;
  }

  .starting11-graphic-card__line {
    display: block;
    width: 340px;
    height: 10px;
    background: var(--s11-gold);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
  }

  .starting11-graphic-card__opponent {
    color: #fff;
    font-size: 2.1rem;
    font-weight: 800;
    text-transform: uppercase;
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__lineup {
    display: grid;
    gap: 0.15rem;
    margin-top: 30px;
  }

  .starting11-graphic-card__player {
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 0.55rem;
    color: #fff;
    font-size: 2.9rem;
    font-weight: 800;
    letter-spacing: -0.04em;
    line-height: 0.98;
    text-transform: uppercase;
    text-shadow: 0 4px 12px rgba(0, 0, 0, 0.26);
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__player-number {
    color: var(--s11-gold);
    font-size: 0.74em;
    font-weight: 900;
    letter-spacing: 0;
    min-width: 1.7em;
    text-align: right;
  }

  .starting11-graphic-card__captain {
    position: relative;
    width: 1.6rem;
    height: 2rem;
    margin-left: 0.15rem;
    border-radius: 0.12rem;
    overflow: hidden;
    transform: translateY(0.08rem);
  }

  .starting11-graphic-card__captain::before,
  .starting11-graphic-card__captain::after {
    content: '';
    position: absolute;
    left: 0;
    width: 100%;
    height: 50%;
  }

  .starting11-graphic-card__captain::before {
    top: 0;
    background: linear-gradient(180deg, var(--s11-maroon) 0%, var(--s11-maroon-dark) 100%);
  }

  .starting11-graphic-card__captain::after {
    bottom: 0;
    background: linear-gradient(180deg, var(--s11-gold) 0%, var(--s11-gold-dark) 100%);
  }

  .starting11-graphic-card__subs {
    display: grid;
    gap: 0.6rem;
    margin-top: 1.4rem;
    padding-top: 0.8rem;
    justify-items: center;
  }

  .starting11-graphic-card__subs-title {
    margin: 0;
    color: var(--s11-gold) !important;
    font-size: 4rem;
    font-weight: 900;
    letter-spacing: -0.06em;
    line-height: 0.96;
    text-transform: uppercase;
    text-shadow: 0 4px 12px rgba(0, 0, 0, 0.24);
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__subs-list {
    max-width: 820px;
    display: block;
    margin: 0;
    color: #fff;
    font-size: 1.3rem;
    font-weight: 700;
    line-height: 1.1;
    text-align: center;
    text-shadow: 0 4px 10px rgba(0, 0, 0, 0.22);
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__subs-row {
    margin: 0;
  }

  .starting11-graphic-card__sponsor-strip {
    display: flex;
    justify-content: center;
    align-items: flex-end;
    gap: 1.8rem;
    width: 100%;
    margin-top: 1rem;
    padding: 0.85rem 42px 34px;
    box-sizing: border-box;
    border-top: 1px solid rgba(255, 255, 255, 0.26);
  }

  .starting11-graphic-card__sponsor {
    flex: 0 1 220px;
    display: flex;
    justify-content: center;
    align-items: flex-end;
    min-height: 80px;
  }

  .starting11-graphic-card__sponsor-logo {
    width: min(100%, 210px);
    max-height: 64px;
    object-fit: contain;
    object-position: center bottom;
    filter: drop-shadow(0 8px 18px rgba(0, 0, 0, 0.25));
  }

  .starting11-graphic-card__sponsor-name {
    color: rgba(255, 247, 230, 0.96);
    font-size: 1.02rem;
    font-weight: 700;
    text-align: center;
    line-height: 1.15;
    text-shadow: 0 6px 14px rgba(0, 0, 0, 0.3);
  }

  .starting11-graphic-card__lineup--grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0;
    width: 100%;
    margin-top: 0;
    align-content: start;
    overflow: hidden;
    height: 100%;
  }

  .starting11-graphic-card__grid-intro,
  .starting11-graphic-card__grid-player,
  .starting11-graphic-card__grid-subs,
  .starting11-graphic-card__grid-subs-list-card {
    position: relative;
    display: grid;
    grid-template-rows: minmax(0, 1fr) auto auto;
    min-height: 0;
    height: 100%;
    background:
      linear-gradient(180deg, rgba(123, 34, 56, 0.92) 0%, rgba(104, 23, 43, 0.96) 100%),
      radial-gradient(circle at top, rgba(255, 255, 255, 0.12), transparent 55%);
    border: 0;
    border-radius: 0;
    overflow: hidden;
  }

  .starting11-graphic-card__grid-intro {
    grid-template-rows: 1fr;
    padding: 0;
    align-items: stretch;
    justify-items: stretch;
    text-align: left;
    background:
      linear-gradient(90deg, rgba(240, 193, 26, 0.98) 0 21%, rgba(0, 0, 0, 0) 21%),
      linear-gradient(180deg, rgba(123, 34, 56, 0.98) 0%, rgba(104, 23, 43, 0.98) 100%);
  }

  .starting11-graphic-card__grid-intro-inner {
    display: grid;
    grid-template-rows: auto 1fr auto;
    align-content: stretch;
    height: 100%;
    padding: 0.65rem 0.85rem 0.8rem 1.15rem;
    gap: 0.35rem;
  }

  .starting11-graphic-card__grid-intro-badge-wrap {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    gap: 0.55rem;
    min-height: 70px;
  }

  .starting11-graphic-card__grid-intro-badge {
    width: 74px;
    max-width: 100%;
    height: 74px;
    object-fit: contain;
    filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.28));
  }

  .starting11-graphic-card__grid-intro-score {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto;
    align-content: start;
    align-items: center;
    justify-items: center;
    width: 100%;
    max-width: 170px;
    margin: -0.15rem auto 0;
  }

  .starting11-graphic-card__grid-intro-abbr {
    color: #fff;
    font-size: 3.15rem;
    font-weight: 900;
    line-height: 0.85;
    letter-spacing: -0.08em;
    text-transform: uppercase;
    text-shadow: 0 4px 10px rgba(0, 0, 0, 0.22);
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__grid-intro-abbr--home {
    grid-column: 1 / span 2;
    grid-row: 1;
    justify-self: start;
  }

  .starting11-graphic-card__grid-intro-abbr--away {
    grid-column: 2;
    grid-row: 2;
    justify-self: start;
    margin-left: -0.35rem;
    margin-top: -0.5rem;
  }

  .starting11-graphic-card__grid-intro-vs {
    color: #fff;
    font-size: 1.5rem;
    font-weight: 200;
    line-height: 1;
    letter-spacing: -0.06em;
    text-transform: uppercase;
    font-family: var(--s11-display-font);
    grid-column: 1;
    grid-row: 2;
    justify-self: left;
    align-self: start;
  }

  .starting11-graphic-card__grid-intro-opponent {
    color: rgba(255, 255, 255, 0.72);
    font-size: 0.78rem;
    font-weight: 800;
    line-height: 1.1;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    text-align: center;
    align-self: end;
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__grid-intro-sponsor {
    display: flex;
    align-items: center;
    justify-content: center;
    align-self: end;
    min-height: 34px;
  }

  .starting11-graphic-card__grid-intro-sponsor-logo {
    width: 100%;
    object-fit: contain;
    filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.22));
  }

  .starting11-graphic-card__grid-intro-sponsor-text {
    color: rgba(255, 248, 235, 0.92);
    font-size: 0.78rem;
    font-weight: 800;
    line-height: 1.1;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    text-align: center;
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__grid-number {
    position: absolute;
    top: 8px;
    left: 12px;
    z-index: 3;
    min-width: 2.1rem;
    color: #fff;
    font-size: 7.35rem;
    font-weight: 900;
    line-height: 0.88;
    letter-spacing: -0.08em;
    text-align: left;
    text-shadow: 0 4px 10px rgba(0, 0, 0, 0.22);
    font-family: "Bebas Neue", "Futura Extra Bold", "Futura", "Trebuchet MS", "Arial Black", sans-serif;
  }

  .starting11-graphic-card__grid-photo-wrap::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.02) 55%, rgba(0, 0, 0, 0.32) 100%);
    pointer-events: none;
  }

  .starting11-graphic-card__grid-player-name-band {
    background: linear-gradient(180deg, rgba(255, 136, 0, 0.96) 0%, rgba(236, 106, 0, 0.98) 100%);
    color: #fff;
    padding: 0.35rem 0.55rem;
    font-size: 0.88rem;
    font-weight: 900;
    letter-spacing: 0.02em;
    line-height: 1;
    text-transform: uppercase;
    min-height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__grid-name {
    background: #f4e6cf;
    color: var(--s11-maroon-dark);
    padding: 0.55rem 0.55rem 0.55rem;
    font-size: 1.4rem;
    text-transform: uppercase;
    text-align: center;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.35rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    line-height: 1.05;
    min-height: 48px;
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__grid-name--alt {
    background: var(--s11-gold);
    color: #3f0f1d;
  }

  .starting11-graphic-card__grid-photo-wrap {
    position: relative;
    min-height: 0;
    overflow: hidden;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(0, 0, 0, 0.12)),
      linear-gradient(180deg, rgba(123, 34, 56, 0.24), rgba(123, 34, 56, 0));
  }

  .starting11-graphic-card__grid-photo {
    width: 100%;
    height: 100%;
    min-height: 0;
    object-position: center center;
    display: block;
    background: rgba(123, 34, 56, 0.18);
  }

  .starting11-graphic-card__grid-photo-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #fff;
    font-size: 2.6rem;
    background: linear-gradient(180deg, rgba(145, 48, 73, 0.95) 0%, rgba(94, 21, 39, 0.98) 100%);
  }

  .starting11-graphic-card__grid-sponsors {
    display: grid;
    gap: 0.75rem;
    padding: 0.75rem;
    background: rgba(255, 248, 235, 0.96);
    border-top: 1px solid rgba(123, 34, 56, 0.14);
  }

  .starting11-graphic-card__grid-sponsor {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    text-align: center;
  }

  .starting11-graphic-card__grid-label {
    font-size: 0.62rem;
    font-weight: 800;
    color: #7b2238;
    text-transform: uppercase;
    letter-spacing: 0.08em;
  }

  .starting11-graphic-card__grid-logo {
    width: 100%;
    max-width: 112px;
    height: 46px;
    object-fit: contain;
  }

  .starting11-graphic-card__grid-text {
    font-size: 0.8rem;
    font-weight: 700;
    color: #132238;
    line-height: 1.15;
  }

  .starting11-graphic-card__grid-empty {
    font-size: 0.8rem;
    font-weight: 700;
    color: rgba(123, 34, 56, 0.7);
  }

  .starting11-graphic-card__grid-subs {
    grid-template-rows: 1fr;
    align-items: center;
    justify-items: center;
    padding: 1rem;
    text-align: left;
  }

  .starting11-graphic-card__grid-subs-title {
    margin: 0;
    color: var(--s11-gold) !important;
    font-size: 3rem;
    font-weight: 900;
    line-height: 0.95;
    letter-spacing: -0.04em;
    text-transform: uppercase;
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__grid-subs-list-card {
    grid-template-rows: 1fr;
    padding: 1rem 1rem 0.9rem;
    align-items: center;
    justify-items: start;
    text-align: left;
  }

  .starting11-graphic-card__grid-subs-list {
    color: #fff;
    font-size: 0.88rem;
    font-weight: 800;
    line-height: 1.28;
    text-transform: uppercase;
    text-shadow: 0 4px 10px rgba(0, 0, 0, 0.22);
    font-family: var(--s11-display-font);
  }

  .starting11-graphic-card__grid-subs-list-item {
    display: block;
  }

  @media (max-width: 1200px) {
    .starting11-graphic-preview-wrap {
      justify-content: flex-start;
    }
  }

  @media (max-width: 991.98px) {
    .starting11-graphic-card__lineup--grid {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
  }
