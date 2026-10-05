<section class="space-y-8">
    <header class="text-center space-y-2">
        <p class="text-xs uppercase tracking-[0.3em] text-slate-500">Internal Setup</p>
        <h1 class="text-3xl font-semibold text-white">Create a Club</h1>
        <p class="text-slate-400">Provision a new club and its first administrator without touching SQL.</p>
    </header>

    <?php if (!empty($errors)): ?>
        <div class="rounded-2xl border border-red-500/40 bg-red-500/10 px-6 py-4 text-sm text-red-200">
            <ul class="list-disc list-inside space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="/setup" class="space-y-10">
        <?= csrf_field(); ?>

        <section class="space-y-4">
            <h2 class="text-xl font-semibold text-white">Club Details</h2>
            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="club_name">Club Name</label>
                    <input id="club_name" name="club_name" type="text" value="<?= htmlspecialchars($old['club_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" placeholder="Placeholder FC" required />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="club_slug">Club Slug</label>
                    <input id="club_slug" name="club_slug" type="text" value="<?= htmlspecialchars($old['club_slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" placeholder="placeholder-fc" />
                    <p class="text-xs text-slate-500 mt-1">Leave blank to auto-generate from the club name.</p>
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-xl font-semibold text-white">Branding</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="primary_colour">Primary Colour</label>
                    <input id="primary_colour" name="primary_colour" type="text" value="<?= htmlspecialchars($old['primary_colour'] ?? '#1d4ed8', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="secondary_colour">Secondary Colour</label>
                    <input id="secondary_colour" name="secondary_colour" type="text" value="<?= htmlspecialchars($old['secondary_colour'] ?? '#0f172a', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="accent_colour">Accent Colour</label>
                    <input id="accent_colour" name="accent_colour" type="text" value="<?= htmlspecialchars($old['accent_colour'] ?? '#22d3ee', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-xl font-semibold text-white">First Club Admin</h2>
            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="admin_name">Full Name</label>
                    <input id="admin_name" name="admin_name" type="text" value="<?= htmlspecialchars($old['admin_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" placeholder="Alex Manager" required />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="admin_email">Email Address</label>
                    <input id="admin_email" name="admin_email" type="email" value="<?= htmlspecialchars($old['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" placeholder="alex@placeholderfc.com" required />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2" for="admin_password">Password</label>
                    <input id="admin_password" name="admin_password" type="password" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" placeholder="At least 8 characters" required />
                </div>
            </div>
        </section>

        <div class="flex items-center justify-between">
            <p class="text-xs text-slate-500">Platform admins only. No public access.</p>
            <button type="submit" class="px-6 py-3 rounded-xl bg-accent text-slate-900 font-semibold tracking-wide">Create Club</button>
        </div>
    </form>
</section>
