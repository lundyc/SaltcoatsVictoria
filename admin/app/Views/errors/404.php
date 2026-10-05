<section class="text-center py-20 space-y-4">
    <p class="text-sm uppercase tracking-[0.3em] text-slate-500">404</p>
    <h1 class="text-3xl font-semibold text-white">Page Not Found</h1>
    <p class="text-slate-400 max-w-xl mx-auto">
        <?= htmlspecialchars($message ?? 'The requested resource could not be located.', ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div class="flex items-center justify-center gap-4 pt-6">
        <a href="<?= htmlspecialchars(club_url('login'), ENT_QUOTES, 'UTF-8') ?>" class="px-5 py-3 rounded-lg bg-accent text-slate-900 font-semibold">Return to Login</a>
    </div>
</section>
