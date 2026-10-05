<section class="space-y-8">
    <header class="flex items-center justify-between">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-slate-500">Administration</p>
            <h1 class="text-3xl font-semibold text-white">User Management</h1>
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
        <h2 class="text-xl font-semibold text-white">Invite New User</h2>
        <form method="POST" action="<?= htmlspecialchars(club_url('admin/users/invite'), ENT_QUOTES, 'UTF-8') ?>" class="grid md:grid-cols-3 gap-4">
            <?= csrf_field(); ?>
            <input type="email" name="invite_email" placeholder="person@club.com" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white focus:outline-none focus:border-accent" required />
            <select name="invite_role" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white focus:outline-none focus:border-accent" required>
                <option value="">Select role…</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= htmlspecialchars($role['code'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-6 py-3 rounded-xl bg-accent text-slate-900 font-semibold">Send Invite</button>
        </form>
    </section>

    <section class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 space-y-4">
        <h2 class="text-xl font-semibold text-white">Current Users</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-slate-300">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="text-left py-2">Name</th>
                        <th class="text-left py-2">Email</th>
                        <th class="text-left py-2">Role</th>
                        <th class="text-left py-2">Status</th>
                        <th class="text-left py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-white"><?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="py-3 pr-4">
                                <p><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
                            </td>
                            <td class="py-3 pr-4">
                                <form method="POST" action="<?= htmlspecialchars(club_url('admin/users/' . $user['id'] . '/role'), ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-2">
                                    <?= csrf_field(); ?>
                                    <select name="role_code" class="px-3 py-2 rounded-lg bg-slate-950/60 border border-slate-800 text-white text-sm">
                                        <?php if (!isset($roles[$user['role_code']])): ?>
                                            <option value="<?= htmlspecialchars($user['role_code'], ENT_QUOTES, 'UTF-8') ?>" selected>
                                                <?= htmlspecialchars($user['role_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endif; ?>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= htmlspecialchars($role['code'], ENT_QUOTES, 'UTF-8') ?>" <?= $role['code'] === $user['role_code'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="px-3 py-2 rounded-lg bg-slate-800 text-xs">Save</button>
                                </form>
                            </td>
                            <td class="py-3 pr-4">
                                <span class="px-3 py-1 rounded-full text-xs <?= ($user['status'] ?? 'active') === 'suspended' ? 'bg-red-500/10 text-red-300 border border-red-500/40' : 'bg-emerald-500/10 text-emerald-200 border border-emerald-500/40' ?>">
                                    <?= htmlspecialchars(ucfirst($user['status'] ?? 'active'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="py-3">
                                <form method="POST" action="<?= htmlspecialchars(club_url('admin/users/' . $user['id'] . '/status'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="status" value="<?= ($user['status'] ?? 'active') === 'suspended' ? 'active' : 'suspended' ?>">
                                    <button type="submit" class="px-3 py-2 rounded-lg border border-slate-700 text-xs">
                                        <?= ($user['status'] ?? 'active') === 'suspended' ? 'Reactivate' : 'Suspend' ?>
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
