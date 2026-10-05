<section class="space-y-8">
    <header class="flex items-center justify-between">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-slate-500">Administration</p>
            <h1 class="text-3xl font-semibold text-white">Club Settings</h1>
        </div>
        <span class="text-xs text-slate-400">Slug: <?= htmlspecialchars($club['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </header>

    <?php if (!empty($statusMessage)): ?>
        <div class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
            <?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
            <ul class="list-disc list-inside space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars(club_url('admin/club'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" class="space-y-6">
        <?= csrf_field(); ?>
        <section class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h2 class="text-xl font-semibold text-white">Identity</h2>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="name">Club Name</label>
                    <input id="name" name="name" type="text" value="<?= htmlspecialchars($club['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white focus:outline-none focus:border-accent" required />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="short_name">Short Name</label>
                    <input id="short_name" name="short_name" type="text" value="<?= htmlspecialchars($club['short_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white focus:outline-none focus:border-accent" />
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2" for="slug">Slug</label>
                <input id="slug" type="text" value="<?= htmlspecialchars($club['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-800 text-slate-400" readonly />
                <p class="text-xs text-slate-500 mt-1">Used in portal URLs. Contact support to change.</p>
            </div>
        </section>

        <section class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h2 class="text-xl font-semibold text-white">Branding</h2>
            <div class="grid md:grid-cols-3 gap-4">
                <?php foreach (['primary_colour' => 'Primary Colour', 'secondary_colour' => 'Secondary Colour', 'accent_colour' => 'Accent Colour'] as $field => $label): ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2" for="<?= $field ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="<?= $field ?>" name="<?= $field ?>" type="text" value="<?= htmlspecialchars($club[$field] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white focus:outline-none focus:border-accent" placeholder="#1d4ed8" />
                    </div>
                <?php endforeach; ?>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2" for="logo">Club Logo</label>
                <input id="logo" name="logo" type="file" accept=".png,.jpg,.jpeg,.svg" class="w-full text-sm text-slate-300" />
                <?php if (!empty($club['logo_path'])): ?>
                    <p class="text-xs text-slate-500 mt-2">Current logo: <span class="text-slate-300"><?= htmlspecialchars($club['logo_path'], ENT_QUOTES, 'UTF-8') ?></span></p>
                <?php endif; ?>
            </div>
        </section>

        <div class="flex items-center justify-between">
            <p class="text-xs text-slate-500">Changes take effect immediately for all staff.</p>
            <button type="submit" class="px-6 py-3 rounded-xl bg-accent text-slate-900 font-semibold tracking-wide">Save Settings</button>
        </div>
    </form>
</section>
