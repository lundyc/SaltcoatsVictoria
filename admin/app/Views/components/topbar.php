<?php
$pageHeading = $pageTitle ?? 'Dashboard';
$user = is_array($authUser ?? null) ? $authUser : [];
$userName = $user['full_name'] ?? ($user['email'] ?? 'Staff');
$logoutAction = $logoutAction ?? club_url('logout');
?>
<header class="px-8 py-5 border-b border-slate-800 bg-slate-900/60 flex items-center justify-between">
    <div>
        <p class="text-xs uppercase tracking-wider text-slate-500">Overview</p>
        <h1 class="text-2xl font-semibold text-white"><?= htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="flex items-center space-x-4">
        <div class="text-right">
            <p class="text-sm text-slate-300"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-xs text-slate-500">Staff</p>
        </div>
        <div class="h-10 w-10 rounded-full bg-slate-800"></div>
        <form method="POST" action="<?= htmlspecialchars($logoutAction, ENT_QUOTES, 'UTF-8') ?>">
            <?= csrf_field(); ?>
            <button type="submit" class="px-4 py-2 rounded-lg bg-accent text-slate-900 text-sm font-semibold">Logout</button>
        </form>
    </div>
</header>
