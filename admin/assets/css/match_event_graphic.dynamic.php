<?= matchTemplatePackFontFaceCss($templatePackBrand) ?>

        .event-share-stage {
            width: <?= (int) $templatePackCanvasWidth ?>px;
            height: <?= (int) $templatePackCanvasHeight ?>px;
        }

        .event-share-card {
            --pack-primary: <?= safe($templatePackPrimary) ?>;
            --pack-accent: <?= safe($templatePackAccent) ?>;
            --pack-text: <?= safe($templatePackText) ?>;
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: var(--pack-primary);
            color: var(--pack-text);
            font-family: "<?= safe($templatePackBodyFont) ?>", "Poppins", Arial, sans-serif;
        }

        .event-share-card__headline,
        .event-share-card__headline-stack,
        .event-share-card__goal-stack,
        .event-share-card__half-time-title,
        .event-share-card__half-time-score,
        .event-share-card__full-time-title,
        .event-share-card__full-time-score,
        .subs-graphic__title {
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Poppins", Impact, sans-serif;
        }

        .event-share-card__headline,
        .event-share-card__headline-stack,
        .event-share-card__goal-stack,
        .event-share-card__half-time-title,
        .event-share-card__half-time-score,
        .event-share-card__full-time-title,
        .event-share-card__full-time-score {
            /* The Substitution title is excluded here: it supports its own
               per-element text_transform override from the pack editor, which
               an !important rule at this shared level would always defeat. */
            text-transform: <?= safe($templatePackHeadingTransform) ?> !important;
        }

        .event-share-card__headline-accent,
        .event-share-card__poster-event-details strong {
            color: var(--pack-accent);
        }

        .event-share-card__poster-image {
            position: absolute;
            inset: 0;
            z-index: 0;
            background-repeat: no-repeat;
            background-position: center;
            background-size: <?= safe($templatePackBackgroundSize) ?>;
        }

        .event-share-card__poster-overlay {
            position: absolute;
            inset: 0;
            z-index: 1;
        }

        .event-share-card--pack-five-kick-off {
            width: <?= (int) $templatePackCanvasWidth ?>px;
            height: <?= (int) $templatePackCanvasHeight ?>px;
            padding: 0;
            border-radius: 0;
            background: #050505;
            box-shadow: none;
        }

        .event-share-card--pack-five-kick-off .event-share-card__poster-image {
            background-position: center;
        }

        .event-share-card--pack-five-score {
            width: <?= (int) $templatePackCanvasWidth ?>px;
            height: <?= (int) $templatePackCanvasHeight ?>px;
            padding: 0;
            border-radius: 0;
            background: #050505;
            box-shadow: none;
        }

        .event-share-card--pack-five-score .event-share-card__poster-image {
            background-position: center;
            filter: saturate(.84) contrast(1.05);
        }

        .pack-five-score__element {
            z-index: 3;
            box-sizing: border-box;
            margin: 0;
            color: #fff;
            text-shadow: 0 5px 22px rgba(0, 0, 0, .88);
        }

        .pack-five-score__headline-word {
            display: flex;
            align-items: center;
            overflow: visible;
            font-family: "<?= safe($templatePackHeadingFont) ?>", Roboto, Arial, sans-serif;
            font-weight: 400;
            line-height: .82;
            letter-spacing: 0;
            text-transform: none !important;
            white-space: nowrap;
        }

        .pack-five-score__headline-word--first {
            justify-content: flex-start;
        }

        .pack-five-score__headline-word--second {
            justify-content: flex-end;
        }

        /* The pack's custom heading font has an oversized right-side bearing on
           the capital "F", leaving a visibly wider gap before the next letter
           than any other pair in "Full Time". Pull the following letter in to
           match the font's normal letter spacing instead of the font's own. */
        .pack-five-score__headline-kern-fix {
            margin-right: -.15em;
        }

        .pack-five-score__score {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .12em;
            overflow: visible;
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Roboto Condensed", Impact, sans-serif;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            line-height: .82;
            letter-spacing: 0;
        }

        .pack-five-score__score span {
            display: inline-flex;
            width: .62em;
            justify-content: center;
        }

        .pack-five-score__score i {
            font-size: .52em;
            font-style: normal;
            line-height: 1;
        }

        .pack-five-score__badge,
        .pack-five-score__competition {
            display: block;
            object-fit: contain;
            filter: drop-shadow(0 5px 14px rgba(0, 0, 0, .5));
        }

        .pack-five-score__badge {
            z-index: 3;
            display: flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            padding: 0;
        }

        .pack-five-score__badge img,
        .pack-five-score__competition img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .pack-five-score__scorers {
            z-index: 3;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            overflow: hidden;
            font-weight: 700;
            line-height: 1.1;
            text-align: center;
            text-transform: uppercase;
        }

        .pack-five-score__fixture-sponsors {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 14px 18px;
            background: rgba(5, 5, 7, .5);
        }

        .pack-five-score__fixture-sponsors small {
            color: rgba(255, 255, 255, .76);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .pack-five-score__fixture-sponsors img {
            display: block;
            width: auto;
            max-width: 300px;
            height: auto;
            max-height: 210px;
            object-fit: contain;
        }

        .pack-five-score__fixture-sponsors strong {
            font-size: 21px;
            line-height: 1.1;
        }

        .pack-five-score__main-sponsors {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 22px;
            padding: 4px 8px;
        }

        .pack-five-score__main-sponsors img {
            display: block;
            width: auto;
            min-width: 0;
            max-width: 30%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 5px 12px rgba(0, 0, 0, .5));
        }

        .pack-five-kick-off__element {
            z-index: 3;
            box-sizing: border-box;
            margin: 0;
            color: #fff;
            text-align: center;
            text-shadow: 0 3px 14px rgba(0, 0, 0, .85);
        }

        .pack-five-kick-off__badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            padding: 0;
        }

        .pack-five-kick-off__badge {
            display: block;
            width: 88px;
            height: 88px;
            object-fit: contain;
            filter: drop-shadow(0 5px 12px rgba(0, 0, 0, .5));
        }

        .pack-five-kick-off__headline {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: visible;
            font-family: "<?= safe($templatePackHeadingFont) ?>", Roboto, Arial, sans-serif;
            font-weight: 400;
            line-height: .82;
            letter-spacing: 0;
            text-transform: none !important;
            white-space: nowrap;
        }

        .pack-five-kick-off__fixture {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            gap: 7px;
            overflow: visible;
            letter-spacing: .045em;
            text-transform: none;
        }

        .pack-five-kick-off__fixture strong,
        .pack-five-kick-off__fixture span {
            display: block;
            color: inherit;
            line-height: 1.1;
            white-space: nowrap;
        }

        .pack-five-kick-off__fixture span {
            font-size: .72em;
            opacity: .86;
        }

        .pack-five-kick-off__player-name {
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: visible;
            font-family: "<?= safe($templatePackBodyFont) ?>", Roboto, Arial, sans-serif;
            font-weight: 600;
            line-height: 1.1;
            letter-spacing: .03em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .pack-five-kick-off__footer-meta {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            overflow: visible;
        }

        .pack-five-kick-off__footer-meta img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .pack-five-kick-off__fixture-sponsors {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 14px 18px;
            background: rgba(5, 5, 7, .5);
        }

        .pack-five-kick-off__fixture-sponsors small {
            color: rgba(255, 255, 255, .76);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .pack-five-kick-off__fixture-sponsors img {
            display: block;
            width: auto;
            max-width: 300px;
            height: auto;
            max-height: 210px;
            object-fit: contain;
        }

        .pack-five-kick-off__fixture-sponsors strong {
            font-size: 21px;
            line-height: 1.1;
        }

        .pack-five-kick-off__main-sponsors {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 22px;
            padding: 0;
        }

        .pack-five-kick-off__main-sponsors img {
            display: block;
            width: auto;
            min-width: 0;
            max-width: 29%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 8px 16px rgba(0, 0, 0, .4));
        }

        .event-share-card--goal {
            padding: 0;
            border-radius: 0;
            background: #eeeeee;
            color: #050505;
        }

        .event-share-card--goal .event-share-card__poster-image {
            background-position: center;
        }

        .event-share-card--pack-five-goal {
            width: <?= (int) $templatePackCanvasWidth ?>px;
            height: <?= (int) $templatePackCanvasHeight ?>px;
            padding: 0;
            border-radius: 0;
            background: #030813;
            color: #fff;
            box-shadow: none;
        }

        .event-share-card--pack-five-goal .event-share-card__poster-image {
            background-position: center;
        }

        .pack-five-goal__element {
            z-index: 3;
            box-sizing: border-box;
            margin: 0;
            color: #fff;
            text-shadow: 0 4px 18px rgba(0, 0, 0, .7);
        }

        .pack-five-goal__meta {
            display: flex;
            align-items: center;
            font-family: Georgia, "Times New Roman", serif;
            font-weight: 500;
            letter-spacing: .055em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .pack-five-goal__meta--left {
            justify-content: flex-start;
        }

        .pack-five-goal__meta--right {
            justify-content: flex-end;
        }

        .pack-five-goal__competition {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding: 0;
            overflow: visible;
        }

        .pack-five-goal__competition img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, .65));
        }

        .pack-five-goal__player {
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: visible;
            font-family: Georgia, "Times New Roman", serif;
            font-style: italic;
            font-weight: 400;
            line-height: 1;
            text-transform: none !important;
            white-space: nowrap;
        }

        .pack-five-goal__headline {
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: visible;
            font-family: "<?= safe($templatePackHeadingFont) ?>", Roboto, Arial, sans-serif;
            font-weight: 400;
            line-height: .82;
            letter-spacing: 0;
            text-transform: none !important;
            white-space: nowrap;
        }

        .pack-five-goal__badges {
            display: flex;
            align-items: center;
            justify-content: space-between !important;
            gap: 0;
            padding: 0;
        }

        .pack-five-goal__badges img {
            display: block;
            width: 155px;
            height: 155px;
            object-fit: contain;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, .65));
        }

        .pack-five-goal__score {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .22em;
            overflow: visible;
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Roboto Condensed", Impact, sans-serif;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            line-height: .82;
            letter-spacing: 0;
            white-space: nowrap;
        }

        .pack-five-goal__score span {
            display: inline-flex;
            width: .62em;
            justify-content: center;
        }

        .pack-five-goal__score i {
            font-size: .52em;
            font-style: normal;
            line-height: 1;
        }

        .pack-five-goal__player-sponsors {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: stretch;
            gap: 12px;
            padding: 14px 18px;
            background: rgba(5, 5, 7, .5);
        }

        .pack-five-goal__player-sponsors.is-single {
            grid-template-columns: minmax(0, 1fr);
        }

        .pack-five-goal__player-sponsor {
            display: flex;
            min-width: 0;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 9px;
        }

        .pack-five-goal__player-sponsor small {
            color: rgba(255, 255, 255, .76);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .pack-five-goal__player-sponsor img {
            display: block;
            width: auto;
            max-width: 300px;
            height: auto;
            max-height: 210px;
            object-fit: contain;
        }

        .pack-five-goal__player-sponsor strong {
            max-width: 100%;
            font-size: 21px;
            line-height: 1.1;
            text-align: center;
        }

        .pack-five-goal__player-sponsor.is-available strong {
            max-width: 240px;
            color: #fff;
            font-weight: 800;
            letter-spacing: .03em;
            text-transform: uppercase;
        }

        /* Goal graphic only: pin the HOME/AWAY label to the top of the cell and
           centre the logo/name as a group in the remaining space below it. */
        .pack-five-goal__player-sponsors .pack-five-goal__player-sponsor {
            justify-content: start;
        }

        .pack-five-goal__player-sponsors .pack-five-goal__player-sponsor small {
            flex: 0 0 auto;
        }

        .pack-five-goal__player-sponsor-body {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
            width: 100%;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 9px;
        }

        .pack-five-goal__player-sponsor--no-logo strong {
            font-size: 44px;
        }

        .pack-five-goal__player-sponsor--no-logo.is-available strong {
            max-width: 100%;
        }

        .event-share-card--pack-five-player-of-match {
            width: <?= (int) $templatePackCanvasWidth ?>px;
            height: <?= (int) $templatePackCanvasHeight ?>px;
            padding: 0;
            border-radius: 0;
            background: #080808;
            color: #fff;
            box-shadow: none;
        }

        .pack-five-player-of-match__element {
            z-index: 3;
            box-sizing: border-box;
            margin: 0;
        }

        .pack-five-player-of-match__headline {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: visible;
            font-family: "<?= safe($templatePackHeadingFont) ?>", Roboto, Arial, sans-serif;
            font-weight: 400;
            line-height: .82;
            letter-spacing: 0;
            text-transform: none !important;
            text-shadow: 0 5px 22px rgba(0, 0, 0, .8);
            white-space: nowrap;
        }

        .pack-five-player-of-match__headline span {
            display: block;
        }

        .pack-five-player-of-match__name {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: visible;
            font-family: "<?= safe($templatePackBodyFont) ?>", Roboto, Arial, sans-serif;
            font-weight: 400;
            line-height: .82;
            letter-spacing: 0;
            text-transform: none !important;
            text-shadow: 0 5px 22px rgba(0, 0, 0, .8);
            white-space: nowrap;
        }

        .pack-five-player-of-match__name span {
            display: block;
        }

        .pack-five-player-of-match__name-first {
            font-weight: 400;
        }

        .pack-five-player-of-match__name-surname {
            font-weight: 800;
        }

        .pack-five-player-of-match__sponsors {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: stretch;
            gap: 12px;
            padding: 14px 18px;
            background: rgba(5, 5, 7, .5);
        }

        .pack-five-player-of-match__badge,
        .pack-five-player-of-match__competition,
        .pack-five-player-of-match__photo {
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .pack-five-player-of-match__badge img,
        .pack-five-player-of-match__competition img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 5px 16px rgba(0, 0, 0, .65));
        }

        .pack-five-player-of-match__photo img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center bottom;
            filter: drop-shadow(0 14px 24px rgba(0, 0, 0, .55));
        }

        .pack-five-player-of-match__photo-fallback {
            display: flex;
            width: 100%;
            height: 100%;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, .22);
            background: rgba(0, 0, 0, .28);
            color: rgba(255, 255, 255, .72);
            font-size: 26px;
            font-weight: 700;
            text-align: center;
        }

        .event-share-card--goal .event-share-card__goal-layout {
            position: absolute;
            inset: 0;
            z-index: 2;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 34px 56px 44px;
        }

        .event-share-card__goal-player {
            position: absolute;
            top: 28px;
            left: 56px;
            right: 56px;
            z-index: 3;
            margin: 0;
            max-width: 900px;
            color: #ffffff;
            font-size: 34px;
            line-height: 1.1;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-align: center;
            text-transform: uppercase;
        }

        .event-share-card__goal-stack {
            display: grid;
            justify-items: center;
            color: #ffffff;
            font-family: "Poppins", Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
            font-size: 190px;
            line-height: 0.82;
            font-weight: 800;
            letter-spacing: -0.055em;
            text-align: center;
            text-transform: uppercase;
            margin-top: 64px;
        }

        .event-share-card__goal-badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            margin-top: auto;
        }

        .event-share-card__goal-badge {
            width: 118px;
            height: 118px;
            object-fit: contain;
            filter: drop-shadow(0 5px 12px rgba(0, 0, 0, 0.18));
        }

        .event-share-card__poster-event-details {
            position: absolute;
            top: 600px;
            left: 0;
            display: grid;
            gap: 7px;
            width: fit-content;
            max-width: 760px;
            margin-top: 0.5rem;
            padding: 12px 18px;
            border-radius: 12px;
            background: rgba(0, 0, 0, 0.42);
            color: #fff;
            font-size: 30px;
            line-height: 1.15;
            font-weight: 700;
            letter-spacing: 0.015em;
            text-transform: uppercase;
        }

        .event-share-card__poster-event-details strong {
            color: #f0bf1d;
        }

        .event-share-card--substitution {
            padding: 0;
            border-radius: 0;
            background: #0d0d0d;
            color: #fff;
        }

        .subs-graphic {
            position: absolute;
            inset: 0;
            z-index: 2;
            overflow: hidden;
            color: #fff;
        }

        .subs-graphic__row {
            position: absolute;
            z-index: 3;
            display: flex;
            align-items: center;
            gap: 26px;
        }

        .subs-graphic__row--off {
            justify-content: flex-end;
        }

        .subs-graphic__tag {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 26px 38px;
            background: #ffffff;
            color: #000000;
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Poppins", Impact, sans-serif;
            font-size: 42px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: 0.01em;
            text-transform: uppercase;
        }

        .subs-graphic__pairs {
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-width: 0;
        }

        .subs-graphic__pair {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .subs-graphic__pair--off {
            justify-content: flex-end;
        }

        .subs-graphic__number {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 56px;
            padding: 6px 12px;
            background: #fff;
            color: #101010;
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Poppins", Impact, sans-serif;
            font-size: 32px;
            line-height: 1;
            font-weight: 800;
        }

        .subs-graphic__name {
            max-width: 520px;
            font-family: "<?= safe($templatePackBodyFont) ?>", "Poppins", Arial, sans-serif;
            font-size: 40px;
            line-height: 1.08;
            font-weight: 800;
            letter-spacing: -0.01em;
            text-transform: uppercase;
        }

        .subs-graphic__pair--off .subs-graphic__name {
            text-align: right;
        }

        .subs-graphic__title {
            position: absolute;
            z-index: 2;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: visible;
            color: #fff;
            font-size: 200px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -0.03em;
            text-transform: uppercase;
        }

        .subs-graphic__title span {
            display: inline-block;
        }

        .subs-graphic__accent {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: #f2f2f2;
            font-size: 46px;
            line-height: 1;
            z-index: 2;
        }

        .subs-graphic__accent--left {
            left: 60px;
        }

        .subs-graphic__accent--right {
            right: 60px;
        }

        .subs-graphic__badges {
            position: absolute;
            z-index: 3;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 18px;
            padding: 0;
        }

        .subs-graphic__badges img {
            display: block;
            width: 88px;
            height: 88px;
            object-fit: contain;
            filter: drop-shadow(0 5px 12px rgba(0, 0, 0, .5));
        }

        .subs-graphic__fixture-sponsors {
            position: absolute;
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            gap: 9px;
            padding: 14px 18px;
            background: rgba(5, 5, 7, .5);
        }

        .subs-graphic__fixture-sponsors small {
            color: rgba(255, 255, 255, .76);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .subs-graphic__fixture-sponsors img {
            display: block;
            width: auto;
            max-width: 220px;
            height: auto;
            max-height: 80px;
            object-fit: contain;
        }

        .subs-graphic__fixture-sponsors strong {
            font-size: 21px;
            line-height: 1.1;
        }

        .subs-graphic__main-sponsors {
            position: absolute;
            z-index: 3;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 22px;
            padding: 0;
        }

        .subs-graphic__main-sponsors img {
            display: block;
            width: auto;
            min-width: 0;
            max-width: 29%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 8px 16px rgba(0, 0, 0, .4));
        }

        .subs-graphic__footer-meta {
            position: absolute;
            z-index: 3;
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }

        .subs-graphic__footer-meta img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .event-share-card--half-time {
            padding: 0;
            border-radius: 0;
            background: #111;
            color: #fff;
        }

        .event-share-card--half-time .event-share-card__poster-image {
            background-position: center;
        }

        .event-share-card__half-time-layout {
            position: absolute;
            inset: 0;
            z-index: 2;
            padding: 44px 54px 48px;
            color: #fff;
        }

        .event-share-card__half-time-title {
            margin: 0;
            color: #fff;
            font-family: "Poppins", Impact, Haettenschweiler, sans-serif;
            font-size: 134px;
            line-height: 0.95;
            font-weight: 800;
            letter-spacing: -0.065em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .event-share-card__half-time-scorers {
            position: absolute;
            top: 205px;
            left: 62px;
            width: 470px;
            display: grid;
            gap: 8px;
            color: #fff;
            font-size: 29px;
            line-height: 1.15;
            font-weight: 700;
            letter-spacing: 0.015em;
            text-transform: uppercase;
        }

        .event-share-card__half-time-score {
            position: absolute;
            top: 185px;
            right: 58px;
            color: #fff;
            font-family: "Poppins", Impact, Haettenschweiler, sans-serif;
            font-size: 220px;
            line-height: 0.9;
            font-weight: 800;
            letter-spacing: -0.075em;
        }

        .event-share-card__half-time-badges {
            position: absolute;
            right: 58px;
            bottom: 48px;
            display: grid;
            gap: 16px;
            justify-items: center;
        }

        .event-share-card__half-time-badge {
            width: 112px;
            height: 112px;
            object-fit: contain;
            filter: drop-shadow(0 5px 12px rgba(0, 0, 0, 0.24));
        }

        .event-share-card--full-time {
            padding: 0;
            border-radius: 0;
            background: #111;
            color: #fff;
        }

        .event-share-card--full-time .event-share-card__poster-image {
            background-position: center;
        }

        .event-share-card__full-time-layout {
            position: absolute;
            inset: 0;
            z-index: 2;
            padding: 46px 56px 48px;
            color: #fff;
        }

        .event-share-card__full-time-score {
            position: absolute;
            top: 42px;
            right: 58px;
            color: #fff;
            font-family: "Poppins", Impact, Haettenschweiler, sans-serif;
            font-size: 220px;
            line-height: 0.9;
            font-weight: 800;
            letter-spacing: -0.075em;
        }

        .event-share-card__full-time-scorers {
            position: absolute;
            top: 250px;
            right: 62px;
            width: 455px;
            display: grid;
            gap: 8px;
            color: #fff;
            font-size: 29px;
            line-height: 1.15;
            font-weight: 700;
            letter-spacing: 0.015em;
            text-transform: uppercase;
        }

        .event-share-card__full-time-title {
            position: absolute;
            left: 52px;
            bottom: 46px;
            display: grid;
            margin: 0;
            color: #fff;
            font-family: "Poppins", Impact, Haettenschweiler, sans-serif;
            font-size: 154px;
            line-height: 0.78;
            font-weight: 800;
            letter-spacing: -0.07em;
            text-transform: uppercase;
        }

        .event-share-card__full-time-badges {
            position: absolute;
            right: 58px;
            bottom: 48px;
            display: grid;
            gap: 16px;
            justify-items: center;
        }

        .event-share-card__full-time-badge {
            width: 112px;
            height: 112px;
            object-fit: contain;
            filter: drop-shadow(0 5px 12px rgba(0, 0, 0, 0.28));
        }
