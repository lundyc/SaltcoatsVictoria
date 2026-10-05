<section class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
    <?php foreach ($widgets as $widget): ?>
        <?php component('dashboard_widget', $widget); ?>
    <?php endforeach; ?>
</section>

<section class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <p class="text-xs uppercase tracking-widest text-slate-500">Club Snapshot</p>
                    <h2 class="text-xl font-semibold text-white">First Team Overview</h2>
                </div>
                <span class="text-xs text-slate-400">Updated daily</span>
            </div>
            <div class="grid sm:grid-cols-2 gap-4 text-sm text-slate-300">
                <div class="bg-slate-900/40 border border-slate-800 rounded-xl p-4">
                    <p class="text-slate-500 text-xs uppercase">Morale</p>
                    <p class="text-2xl font-semibold text-white">High</p>
                    <p class="text-slate-400 text-xs">Squad harmony remains strong</p>
                </div>
                <div class="bg-slate-900/40 border border-slate-800 rounded-xl p-4">
                    <p class="text-slate-500 text-xs uppercase">Dynamics</p>
                    <p class="text-2xl font-semibold text-white">Stable</p>
                    <p class="text-slate-400 text-xs">Leadership support in place</p>
                </div>
            </div>
        </div>

        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <p class="text-xs uppercase tracking-widest text-slate-500">Training Schedule</p>
                    <h2 class="text-xl font-semibold text-white">Upcoming Focus</h2>
                </div>
                <span class="text-xs text-slate-400">Next 4 Days</span>
            </div>
            <ul class="space-y-4">
                <?php
                $sessions = [
                    ['day' => 'Thu', 'focus' => 'Attacking Shape', 'intensity' => 'High'],
                    ['day' => 'Fri', 'focus' => 'Set Pieces', 'intensity' => 'Medium'],
                    ['day' => 'Sat', 'focus' => 'Match Preview', 'intensity' => 'Low'],
                    ['day' => 'Sun', 'focus' => 'Recovery', 'intensity' => 'Low'],
                ];
                foreach ($sessions as $session): ?>
                    <li class="flex items-center justify-between bg-slate-900/40 border border-slate-800 rounded-xl px-4 py-3">
                        <div>
                            <p class="text-sm font-semibold text-white"><?= htmlspecialchars($session['focus'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="text-xs text-slate-500"><?= htmlspecialchars($session['day'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <span class="text-xs px-3 py-1 rounded-full border border-slate-700 text-slate-200">
                            <?= htmlspecialchars($session['intensity'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <aside class="space-y-4">
        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-white">Inbox</h2>
                <span class="text-xs text-slate-500">3 unread</span>
            </div>
            <div class="space-y-3">
                <?php foreach ($inboxItems as $item): ?>
                    <?php component('inbox_item', $item); ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6">
            <p class="text-xs uppercase tracking-widest text-slate-500 mb-2">Alerts</p>
            <h2 class="text-lg font-semibold text-white">Match Readiness</h2>
            <p class="text-sm text-slate-400 mt-2">All departments aligned for the upcoming fixture. Tactical briefing and media duties confirmed.</p>
        </div>
    </aside>
</section>
