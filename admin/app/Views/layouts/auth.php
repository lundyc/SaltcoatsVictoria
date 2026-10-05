<?php
$club = $club ?? ['theme' => app_config('theme')];
$theme = array_merge(app_config('theme'), $theme ?? [], $club['theme'] ?? []);
$pageHeading = $pageTitle ?? 'Sign In';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageHeading . ' | ' . ($club['name'] ?? app_config('name')), ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '<?= htmlspecialchars($theme['primary'] ?? '#7f9cf5', ENT_QUOTES, 'UTF-8') ?>',
                        secondary: '<?= htmlspecialchars($theme['secondary'] ?? '#1e293b', ENT_QUOTES, 'UTF-8') ?>',
                        background: '<?= htmlspecialchars($theme['background'] ?? '#020617', ENT_QUOTES, 'UTF-8') ?>',
                        panel: '<?= htmlspecialchars($theme['panel'] ?? '#111827', ENT_QUOTES, 'UTF-8') ?>',
                        accent: '<?= htmlspecialchars($theme['accent'] ?? '#22d3ee', ENT_QUOTES, 'UTF-8') ?>',
                    }
                }
            }
        };
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-primary via-background to-panel text-slate-100">
    <div class="min-h-screen flex items-center justify-center p-6">
        <div class="max-w-md w-full">
            <div class="flex flex-col items-center text-center mb-8">
                <?php if (!empty($club['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($club['logo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($club['name'] ?? 'Club', ENT_QUOTES, 'UTF-8') ?>" class="h-20 w-20 rounded-full object-cover bg-slate-800 mb-4">
                <?php else: ?>
                    <div class="h-20 w-20 rounded-full bg-slate-800 mb-4"></div>
                <?php endif; ?>
                <h1 class="text-3xl font-semibold"><?= htmlspecialchars($club['name'] ?? app_config('name'), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-sm text-slate-300">Secure staff portal</p>
            </div>
            <section class="bg-slate-900/80 border border-slate-800 rounded-2xl p-8 shadow-2xl shadow-black/50">
                <?= $content ?>
            </section>
        </div>
    </div>
</body>
</html>
