<?php
$club = is_array($club ?? null) ? $club : [];
$clubName = $club['name'] ?? 'Your Club';
$sections = [
    'Operations' => [
        ['label' => 'Dashboard', 'href' => club_url('dashboard')],
        ['label' => 'Fixtures', 'href' => '#'],
        ['label' => 'People', 'href' => '#'],
    ],
    'Performance' => [
        ['label' => 'Training', 'href' => '#'],
        ['label' => 'Comms', 'href' => '#'],
        ['label' => 'Finance', 'href' => '#'],
    ],
];

if (is_club_admin()) {
    $sections['Admin'] = [
        ['label' => 'Admin Dashboard', 'href' => club_url('admin')],
        ['label' => 'Club Settings', 'href' => club_url('admin/club')],
        ['label' => 'User Management', 'href' => club_url('admin/users')],
        ['label' => 'Sections', 'href' => club_url('admin/sections')],
    ];
}
?>
<aside class="w-64 bg-slate-900/70 border-r border-slate-800 flex flex-col">
    <div class="px-6 py-6 border-b border-slate-800 flex items-center space-x-3">
        <?php if (!empty($club['logo_path'])): ?>
            <img src="<?= htmlspecialchars($club['logo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8') ?>" class="h-12 w-12 rounded-full object-cover bg-slate-800">
        <?php else: ?>
            <div class="h-12 w-12 rounded-full bg-slate-800"></div>
        <?php endif; ?>
        <div>
            <div class="text-lg font-semibold text-slate-100">
                <?= htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <p class="text-xs text-slate-400">Season 2026/27</p>
        </div>
    </div>
    <nav class="flex-1 px-4 py-6 space-y-6 overflow-y-auto">
        <?php foreach ($sections as $section => $items): ?>
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-500 mb-2"><?= htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?></p>
                <div class="space-y-1">
                    <?php foreach ($items as $item): ?>
                        <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="block px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
                            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="px-6 py-4 border-t border-slate-800 text-xs text-slate-500">
        v0.2 Auth
    </div>
</aside>
