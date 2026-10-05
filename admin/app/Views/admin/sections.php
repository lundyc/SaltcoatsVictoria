<section class="space-y-8">
    <header class="flex items-center justify-between">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-slate-500">Administration</p>
            <h1 class="text-3xl font-semibold text-white">Sections</h1>
        </div>
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

    <section class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 space-y-4">
        <h2 class="text-xl font-semibold text-white">Add Section</h2>
        <form method="POST" action="<?= htmlspecialchars(club_url('admin/sections'), ENT_QUOTES, 'UTF-8') ?>" class="flex flex-col md:flex-row gap-4">
            <?= csrf_field(); ?>
            <input type="text" name="name" placeholder="Section name" class="flex-1 px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white focus:outline-none focus:border-accent" required />
            <button type="submit" class="px-6 py-3 rounded-xl bg-accent text-slate-900 font-semibold">Add Section</button>
        </form>
    </section>

    <section class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 space-y-4">
        <h2 class="text-xl font-semibold text-white">Existing Sections</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-slate-300">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="text-left py-2">Name</th>
                        <th class="text-left py-2">Order</th>
                        <th class="text-left py-2">Status</th>
                        <th class="text-left py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php foreach ($sections as $section): ?>
                        <tr>
                            <td class="py-3 font-semibold text-white"><?= htmlspecialchars($section['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="py-3">
                                <form method="POST" action="<?= htmlspecialchars(club_url('admin/sections/' . $section['id'] . '/sort'), ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-2">
                                    <?= csrf_field(); ?>
                                    <input type="number" name="sort_order" value="<?= (int) $section['sort_order'] ?>" class="w-20 px-3 py-2 rounded-lg bg-slate-950/60 border border-slate-800 text-white" />
                                    <button type="submit" class="px-3 py-2 rounded-lg bg-slate-800 text-xs">Save</button>
                                </form>
                            </td>
                            <td class="py-3">
                                <span class="px-3 py-1 rounded-full text-xs <?= $section['is_active'] ? 'bg-emerald-500/10 text-emerald-200 border border-emerald-500/40' : 'bg-red-500/10 text-red-300 border border-red-500/40' ?>">
                                    <?= $section['is_active'] ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td class="py-3">
                                <form method="POST" action="<?= htmlspecialchars(club_url('admin/sections/' . $section['id'] . '/toggle'), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= csrf_field(); ?>
                                    <button type="submit" class="px-3 py-2 rounded-lg border border-slate-700 text-xs">
                                        <?= $section['is_active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
