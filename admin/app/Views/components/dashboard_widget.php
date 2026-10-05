<article class="bg-slate-900/70 border border-slate-800 rounded-xl p-5 shadow-lg shadow-black/20">
    <p class="text-xs uppercase tracking-widest text-slate-500 mb-2"><?= htmlspecialchars($title ?? 'Metric', ENT_QUOTES, 'UTF-8') ?></p>
    <div class="text-3xl font-semibold text-white mb-1">
        <?= htmlspecialchars($value ?? '-', ENT_QUOTES, 'UTF-8') ?>
    </div>
    <p class="text-sm text-slate-400"><?= htmlspecialchars($subtext ?? '', ENT_QUOTES, 'UTF-8') ?></p>
</article>
