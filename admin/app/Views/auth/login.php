<?php
$errors = $errors ?? [];
$old = $old ?? [];
?>
<?php if (!empty($errors)): ?>
    <div class="mb-6 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
        <ul class="list-disc list-inside space-y-1">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<form method="POST" action="<?= htmlspecialchars(club_url('login'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-6">
    <?= csrf_field(); ?>
    <div>
        <label class="block text-sm font-medium text-slate-300 mb-2" for="email">Club Email</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" placeholder="manager@club.com" required />
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-300 mb-2" for="password">Password</label>
        <input id="password" name="password" type="password" class="w-full px-4 py-3 rounded-xl bg-slate-950/60 border border-slate-800 text-white placeholder-slate-500 focus:outline-none focus:border-accent" placeholder="••••••••" required />
    </div>
    <button type="submit" class="w-full py-3 rounded-xl bg-accent text-slate-900 font-semibold tracking-wide">Sign In</button>
</form>
