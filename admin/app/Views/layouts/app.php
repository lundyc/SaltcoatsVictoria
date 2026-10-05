<?php
$club = $club ?? ['theme' => app_config('theme')];
$theme = array_merge(app_config('theme'), $theme ?? [], $club['theme'] ?? []);
$pageHeading = $pageTitle ?? 'Dashboard';
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
<body class="bg-background text-slate-100 min-h-screen">
    <div class="flex min-h-screen">
        <?php component('sidebar', ['club' => $club]); ?>
        <div class="flex-1 flex flex-col bg-slate-950/80">
            <?php component('topbar', [
                'pageTitle' => $pageHeading,
                'authUser' => $authUser ?? null,
                'logoutAction' => club_url('logout'),
            ]); ?>
            <main class="flex-1 p-6">
                <div class="max-w-6xl mx-auto space-y-6">
                    <?= $content ?>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
