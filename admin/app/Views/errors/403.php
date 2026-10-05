<section class="text-center py-20 space-y-4">
    <p class="text-sm uppercase tracking-[0.3em] text-slate-500">403</p>
    <h1 class="text-3xl font-semibold text-white">Access Restricted</h1>
    <p class="text-slate-400 max-w-xl mx-auto">
        <?= htmlspecialchars($message ?? 'You do not have permission to access this area.', ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div class="flex items-center justify-center gap-4 pt-6">
        <a href="<?= htmlspecialchars(club_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="px-5 py-3 rounded-lg bg-accent text-slate-900 font-semibold">Go to Dashboard</a>
        <a href="<?= htmlspecialchars(club_url('login'), ENT_QUOTES, 'UTF-8') ?>" class="px-5 py-3 rounded-lg border border-slate-700 text-slate-300">Back to Login</a>
    </div>
</section>
