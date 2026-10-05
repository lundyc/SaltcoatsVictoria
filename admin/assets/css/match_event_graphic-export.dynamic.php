*,
            *::before,
            *::after {
                animation: none !important;
                transition: none !important;
            }

            body {
                margin: 0 !important;
            }

            main {
                padding: 0 !important;
            }

            <?php if ($usePackFiveCanvasGraphic): ?>
            html,
            body,
            .page-main--render,
            .page-main--render .container {
                width: <?= (int) $templatePackCanvasWidth ?>px !important;
                height: <?= (int) $templatePackCanvasHeight ?>px !important;
                max-width: <?= (int) $templatePackCanvasWidth ?>px !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .page-main--render {
                overflow: hidden !important;
            }

            .event-share-stage--pack-five-canvas {
                width: <?= (int) $templatePackCanvasWidth ?>px !important;
                height: <?= (int) $templatePackCanvasHeight ?>px !important;
                min-height: <?= (int) $templatePackCanvasHeight ?>px !important;
                padding: 0 !important;
            }
            <?php endif; ?>
