<article class="bg-slate-900/70 border border-slate-800 rounded-lg p-4 flex flex-col gap-1">
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-white">
            <?= htmlspecialchars($title ?? 'Update', ENT_QUOTES, 'UTF-8') ?>
        </h3>
        <span class="text-xs text-slate-500">
            <?= htmlspecialchars($timestamp ?? 'Now', ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
    <p class="text-sm text-slate-400">
        <?= htmlspecialchars($description ?? 'Details to follow.', ENT_QUOTES, 'UTF-8') ?>
    </p>
</article>
