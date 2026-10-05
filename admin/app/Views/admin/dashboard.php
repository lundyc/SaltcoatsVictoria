<section class="space-y-8">
    <header class="flex items-center justify-between">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-slate-500">Administration</p>
            <h1 class="text-3xl font-semibold text-white">Admin Dashboard</h1>
        </div>
        <span class="px-3 py-1 rounded-full border border-slate-700 text-xs text-slate-300">Status: <?= htmlspecialchars($clubInfo['status'] ?? 'active', ENT_QUOTES, 'UTF-8') ?></span>
    </header>

    <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
        <article class="bg-slate-900/80 border border-slate-800 rounded-xl p-5">
            <p class="text-xs uppercase tracking-widest text-slate-500 mb-1">Club Name</p>
            <div class="text-xl font-semibold text-white"><?= htmlspecialchars($clubInfo['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <p class="text-xs text-slate-500 mt-2">Slug: <?= htmlspecialchars($clubInfo['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
        </article>
        <article class="bg-slate-900/80 border border-slate-800 rounded-xl p-5">
            <p class="text-xs uppercase tracking-widest text-slate-500 mb-1">Sections</p>
            <div class="text-3xl font-semibold text-white"><?= (int) ($stats['sections'] ?? 0) ?></div>
            <p class="text-xs text-slate-500 mt-2">Active sections configured</p>
        </article>
        <article class="bg-slate-900/80 border border-slate-800 rounded-xl p-5">
            <p class="text-xs uppercase tracking-widest text-slate-500 mb-1">Teams</p>
            <div class="text-3xl font-semibold text-white"><?= (int) ($stats['teams'] ?? 0) ?></div>
            <p class="text-xs text-slate-500 mt-2">Total club teams</p>
        </article>
        <article class="bg-slate-900/80 border border-slate-800 rounded-xl p-5">
            <p class="text-xs uppercase tracking-widest text-slate-500 mb-1">Users</p>
            <div class="text-3xl font-semibold text-white"><?= (int) ($stats['users'] ?? 0) ?></div>
            <p class="text-xs text-slate-500 mt-2">Members with portal access</p>
        </article>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <section class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6">
            <h2 class="text-xl font-semibold text-white mb-4">Quick Actions</h2>
            <div class="grid sm:grid-cols-2 gap-4">
                <a href="<?= htmlspecialchars(club_url('admin/club'), ENT_QUOTES, 'UTF-8') ?>" class="block rounded-xl border border-slate-700 p-4 hover:border-accent transition">
                    <p class="text-sm text-slate-400">Update branding, names, and logo.</p>
                    <p class="text-lg font-semibold text-white mt-2">Club Settings</p>
                </a>
                <a href="<?= htmlspecialchars(club_url('admin/users'), ENT_QUOTES, 'UTF-8') ?>" class="block rounded-xl border border-slate-700 p-4 hover:border-accent transition">
                    <p class="text-sm text-slate-400">Invite admins and control access.</p>
                    <p class="text-lg font-semibold text-white mt-2">User Management</p>
                </a>
                <a href="<?= htmlspecialchars(club_url('admin/sections'), ENT_QUOTES, 'UTF-8') ?>" class="block rounded-xl border border-slate-700 p-4 hover:border-accent transition">
                    <p class="text-sm text-slate-400">Configure Men/Women/Youth areas.</p>
                    <p class="text-lg font-semibold text-white mt-2">Sections</p>
                </a>
                <a href="<?= htmlspecialchars(club_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="block rounded-xl border border-slate-700 p-4 hover:border-accent transition">
                    <p class="text-sm text-slate-400">Return to operational overview.</p>
                    <p class="text-lg font-semibold text-white mt-2">Club Portal</p>
                </a>
            </div>
        </section>
        <section class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6">
            <h2 class="text-xl font-semibold text-white mb-4">Branding Snapshot</h2>
            <div class="flex flex-wrap gap-4">
                <?php foreach (['primary_colour' => 'Primary', 'secondary_colour' => 'Secondary', 'accent_colour' => 'Accent'] as $key => $label): ?>
                    <div class="flex-1 min-w-[120px]">
                        <div class="h-20 rounded-xl border border-slate-800" style="background: <?= htmlspecialchars($clubInfo[$key] ?? '#1d4ed8', ENT_QUOTES, 'UTF-8') ?>;"></div>
                        <p class="text-xs text-slate-400 mt-2"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-sm text-white font-semibold"><?= htmlspecialchars($clubInfo[$key] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</section>
