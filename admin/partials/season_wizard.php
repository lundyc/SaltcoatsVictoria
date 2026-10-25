<?php
declare(strict_types=1);

/**
 * Add-season setup wizard (included by season.php when creating a season).
 * Posts to season_save.php, which creates the season, its ticket types and the pricing plan.
 *
 * Expects from season.php: $pdo, $copySource, $copySeasonOptions, $sourceId, $playerPricing, $matchPricing,
 * $competitionOptions, $selectedCompetitionId, $availableTemplatePacks, $seasonTemplatePack, $globalTemplatePack,
 * $seasonTicketTerms, $canEditDeadline.
 */

require_once __DIR__ . '/../lib/season_wizard.php';

$canTickets = hub_auth_has_capability('tickets_ops');
$canPackages = hub_auth_has_any_capability(['sponsorship', 'finance_view']);

$sourceTypes = ($canTickets && $sourceId > 0) ? getSeasonTicketTypes($pdo, $sourceId) : [];
$matchTicketPackages = $canTickets ? getMatchTicketPackages($pdo) : [];
$hospitality = $canTickets ? hospitality_settings($pdo) : [];
$sponsorPackages = $canPackages ? getSponsorshipPackages($pdo, true) : [];

$suggestedName = $copySource ? season_wizard_next_name((string) $copySource['name']) : '';
$suggestedStart = $copySource ? season_wizard_shift_year($copySource['start_date'] ?? null) : '';
$suggestedEnd = $copySource ? season_wizard_shift_year($copySource['end_date'] ?? null) : '';
$suggestedDeadline = $copySource ? season_wizard_shift_year($copySource['sponsorship_deadline'] ?? null) : '';

/** A £ input that remembers its original value so the review step can show what changed. */
$price = static function (string $name, string $label, mixed $value, string $label2 = '', string $help = ''): string {
    $v = $value === null ? '' : number_format((float) $value, 2, '.', '');
    return '<div class="wiz-field"><label class="form-label" for="' . h($name) . '">' . h($label) . '</label>'
        . '<div class="input-group"><span class="input-group-text">&pound;</span>'
        . '<input type="text" inputmode="decimal" autocomplete="off" id="' . h($name) . '" name="' . h($name) . '" class="form-control text-end" value="' . h($v) . '" data-money data-orig="' . h($v) . '" data-label="' . h($label2 !== '' ? $label2 : $label) . '"></div>'
        . ($help !== '' ? '<div class="form-text">' . $help . '</div>' : '') . '</div>';
};
$cell = static function (string $name, mixed $value, string $label): string {
    $v = $value === null ? '' : number_format((float) $value, 2, '.', '');
    return '<div class="input-group input-group-sm"><span class="input-group-text">&pound;</span><input type="text" inputmode="decimal" autocomplete="off" name="' . h($name) . '" class="form-control text-end" value="' . h($v) . '" data-money data-orig="' . h($v) . '" data-label="' . h($label) . '" aria-label="' . h($label) . '"></div>';
};
$steps = ['Season', 'Sponsors', 'Season tickets', 'Matchday & hospitality', 'Other prices', 'Review'];
?>
<style>
.wiz-steps { display: flex; gap: .25rem; margin: 0 0 1.25rem; padding: 0; list-style: none; overflow-x: auto; }
.wiz-steps li { flex: 1 1 0; min-width: 7.5rem; }
.wiz-steps button { width: 100%; display: flex; align-items: center; gap: .55rem; padding: .6rem .75rem; border: 1px solid var(--hub-line, rgba(75,8,24,.12)); border-radius: .7rem; background: var(--hub-surface, #fffdf9); color: var(--hub-muted, #70676b); font-size: .88rem; font-weight: 600; text-align: left; }
.wiz-steps .wiz-num { flex: none; display: inline-grid; place-items: center; width: 1.6rem; height: 1.6rem; border-radius: 50%; background: rgba(75,8,24,.08); color: var(--brand-dark, #4b0818); font-size: .8rem; }
.wiz-steps li.is-current button { border-color: var(--brand-primary, #6a2036); color: var(--brand-dark, #4b0818); box-shadow: inset 0 -3px 0 var(--brand-warning, #e0b42a); }
.wiz-steps li.is-current .wiz-num { background: var(--brand-primary, #6a2036); color: #fff; }
.wiz-steps li.is-done .wiz-num { background: #2e9e6b; color: #fff; }
.js .wiz-step { display: none; }
.js .wiz-step.is-current { display: block; }
.wiz-step > header { margin-bottom: 1rem; }
.wiz-step > header h2 { margin: 0; font-size: 1.25rem; font-weight: 700; }
.wiz-step > header p { margin: .25rem 0 0; color: var(--hub-muted, #70676b); }
.wiz-card { padding: 1.15rem 1.25rem; margin-bottom: 1rem; }
.wiz-card > h3 { margin: 0 0 .15rem; font-size: 1rem; font-weight: 700; }
.wiz-card > p.wiz-sub { margin: 0 0 1rem; color: var(--hub-muted, #70676b); font-size: .88rem; }
.wiz-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .9rem 1.1rem; }
.wiz-was { color: var(--hub-muted, #70676b); font-size: .78rem; margin-top: .2rem; }
.wiz-global { border-left: 4px solid var(--brand-warning, #e0b42a); background: rgba(224,180,42,.1); padding: .75rem 1rem; border-radius: .5rem; margin-bottom: 1rem; font-size: .88rem; }
.wiz-nav { position: sticky; bottom: 0; display: flex; justify-content: space-between; gap: .5rem; padding: .85rem 0; background: linear-gradient(to top, var(--hub-canvas, #f6ecde) 70%, transparent); }
.wiz-review li { padding: .35rem 0; border-bottom: 1px solid var(--hub-line, rgba(75,8,24,.12)); }
.wiz-review .wiz-arrow { color: var(--hub-muted, #70676b); }
.wiz-links a { display: flex; align-items: center; gap: .6rem; padding: .6rem .8rem; border: 1px solid var(--hub-line, rgba(75,8,24,.12)); border-radius: .6rem; text-decoration: none; margin-bottom: .5rem; background: #fff; }
.wiz-links small { color: var(--hub-muted, #70676b); display: block; }
@media (max-width: 767.98px) { .wiz-steps li { min-width: 3rem; } .wiz-steps .wiz-label { display: none; } .wiz-steps li.is-current .wiz-label { display: inline; } }
</style>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="seasons.php">Seasons</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Add season</span></nav>

<form method="post" action="season_save.php" id="seasonWizard" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="season_id" value="0">
    <input type="hidden" name="wizard" value="1">

    <ol class="wiz-steps" id="wizSteps" aria-label="Setup steps">
        <?php foreach ($steps as $i => $label): ?>
            <li data-step="<?= $i ?>"><button type="button"><span class="wiz-num"><?= $i + 1 ?></span><span class="wiz-label"><?= h($label) ?></span></button></li>
        <?php endforeach; ?>
    </ol>

    <!-- 1. Season -->
    <section class="wiz-step" data-step="0">
        <header><h2>Season details</h2><p>The name, dates and league. Everything else in this wizard starts from the season you copy.</p></header>
        <div class="hub-panel wiz-card">
            <h3>Start from</h3>
            <p class="wiz-sub">Prices, ticket types, the template pack and ticket terms are pre-filled from this season. Choosing a different one reloads the wizard.</p>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <label class="visually-hidden" for="wizCopyFrom">Copy settings from</label>
                <select class="form-select w-auto" id="wizCopyFrom" onchange="window.location='season.php?action=new&amp;copy_from=' + this.value">
                    <option value="0" <?= !$copySource ? 'selected' : '' ?>>Start from defaults</option>
                    <?php foreach ($copySeasonOptions as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>" <?= $copySource && (int) $opt['id'] === (int) $copySource['id'] ? 'selected' : '' ?>><?= h((string) $opt['name']) ?><?= !empty($opt['is_current']) ? ' (current)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="hub-panel wiz-card">
            <h3>Name &amp; dates</h3>
            <div class="wiz-grid mt-3">
                <div class="wiz-field"><label class="form-label" for="seasonName">Season name</label><input type="text" class="form-control" id="seasonName" name="name" value="<?= h($suggestedName) ?>" placeholder="2027 / 2028" required data-label="Season name"><div class="form-text">Same format as the others, e.g. 2027 / 2028.</div></div>
                <div class="wiz-field"><label class="form-label" for="startDate">Start date</label><input type="date" class="form-control" id="startDate" name="start_date" value="<?= h($suggestedStart) ?>" data-label="Start date"></div>
                <div class="wiz-field"><label class="form-label" for="endDate">End date</label><input type="date" class="form-control" id="endDate" name="end_date" value="<?= h($suggestedEnd) ?>" data-label="End date"></div>
                <div class="wiz-field"><label class="form-label" for="sponsorshipDeadline">Sponsorship deadline</label><input type="date" class="form-control" id="sponsorshipDeadline" name="sponsorship_deadline" value="<?= h($canEditDeadline ? $suggestedDeadline : '') ?>" <?= $canEditDeadline ? '' : 'readonly' ?> data-label="Sponsorship deadline"><div class="form-text"><?= $canEditDeadline ? 'Player sponsorships lock after this date. Blank for none.' : 'Only the club owner can set this.' ?></div></div>
            </div>
        </div>
        <div class="hub-panel wiz-card">
            <h3>League &amp; graphics</h3>
            <div class="wiz-grid mt-3">
                <div class="wiz-field"><label class="form-label" for="competitionId">League competition</label>
                    <select class="form-select" id="competitionId" name="competition_id" data-label="League">
                        <option value="0">Not set yet</option>
                        <?php foreach ($competitionOptions as $competition): ?>
                            <option value="<?= (int) $competition['id'] ?>" <?= (int) $competition['id'] === $selectedCompetitionId ? 'selected' : '' ?>><?= h((string) $competition['name']) ?></option>
                        <?php endforeach; ?>
                    </select><div class="form-text">The hub league table follows this.</div></div>
                <div class="wiz-field"><label class="form-label" for="templatePackId">Template pack</label>
                    <select class="form-select" id="templatePackId" name="template_pack_id" data-label="Template pack">
                        <option value="0">Use global default<?= !empty($globalTemplatePack['pack_name']) ? ' — ' . h((string) $globalTemplatePack['pack_name']) : '' ?></option>
                        <?php foreach ($availableTemplatePacks as $pack): $packId = (int) ($pack['id'] ?? 0); ?>
                            <option value="<?= $packId ?>" <?= (int) ($seasonTemplatePack['pack_id'] ?? 0) === $packId ? 'selected' : '' ?>><?= h((string) ($pack['name'] ?? 'Untitled pack')) ?></option>
                        <?php endforeach; ?>
                    </select><div class="form-text">Design for this season's match graphics.</div></div>
            </div>
        </div>
        <div class="hub-panel wiz-card">
            <h3>Status</h3>
            <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" role="switch" name="is_current" value="1" id="isCurrent" data-label="Make current season now">
                <label class="form-check-label" for="isCurrent">Make this the current season now</label>
            </div>
            <div class="form-text mb-2">Leave this off while you prepare. Club-wide price changes (hospitality, matchday tickets, sponsorship packages) are held back and go live when you later mark the season as current. Turning it on makes them live as soon as you create the season.</div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="is_locked" value="1" id="isLocked" data-label="Lock season">
                <label class="form-check-label" for="isLocked">Locked (cannot be edited)</label>
            </div>
        </div>
    </section>

    <!-- 2. Sponsors -->
    <section class="wiz-step" data-step="1">
        <header><h2>Sponsorship prices</h2><p>What sponsors pay this season. <?= $copySource ? 'Amounts show last season in grey.' : '' ?></p></header>
        <div class="hub-panel wiz-card">
            <h3>Player sponsors</h3><p class="wiz-sub">Per player, by kit.</p>
            <div class="wiz-grid">
                <?= $price('player_home_amount', 'Home kit', $playerPricing['player_home_amount'], 'Player sponsor — home') ?>
                <?= $price('player_away_amount', 'Away kit', $playerPricing['player_away_amount'], 'Player sponsor — away') ?>
                <?= $price('player_third_amount', 'Third kit', $playerPricing['player_third_amount'], 'Player sponsor — third') ?>
            </div>
        </div>
        <div class="hub-panel wiz-card">
            <h3>Matchday sponsors</h3><p class="wiz-sub">Per fixture.</p>
            <div class="wiz-grid">
                <?= $price('match_home_amount', 'Matchday — home game', $matchPricing['home_amount'], 'Matchday sponsor — home') ?>
                <?= $price('match_away_amount', 'Matchday — away game', $matchPricing['away_amount'], 'Matchday sponsor — away') ?>
            </div>
        </div>
        <div class="hub-panel wiz-card">
            <h3>Matchball</h3><p class="wiz-sub">Per fixture.</p>
            <div class="wiz-grid"><?= $price('match_ball_amount', 'Match ball', $matchPricing['match_ball_amount'], 'Match ball sponsor') ?></div>
        </div>
        <?php if ($canPackages && $sponsorPackages): ?>
        <div class="hub-panel wiz-card">
            <h3>Sponsorship packages</h3>
            <p class="wiz-sub">Pitchside boards, club, team and digital packages.</p>
            <div class="wiz-global"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>These prices are club-wide, not per season. Changes are saved with this season and applied when it becomes current. Existing agreements keep the price they were agreed at.</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Package</th><th class="text-end">Price</th><th class="text-end">Away price</th><th class="text-end">Renewal price</th></tr></thead>
                <tbody>
                <?php foreach ($sponsorPackages as $pkg): $pid = (int) $pkg['id']; ?>
                    <tr>
                        <td><?= h((string) $pkg['name']) ?><div class="small text-muted"><?= h(ucfirst((string) ($pkg['scope'] ?? ''))) ?></div></td>
                        <td style="min-width:8rem"><?= $cell("pkg[$pid][amount]", $pkg['amount'], $pkg['name'] . ' price') ?></td>
                        <td style="min-width:8rem"><?= $pkg['away_amount'] !== null ? $cell("pkg[$pid][away_amount]", $pkg['away_amount'], $pkg['name'] . ' away price') : '<span class="text-muted small">n/a</span>' ?></td>
                        <td style="min-width:8rem"><?= $pkg['renewal_amount'] !== null ? $cell("pkg[$pid][renewal_amount]", $pkg['renewal_amount'], $pkg['name'] . ' renewal price') : '<span class="text-muted small">n/a</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>
        <?php endif; ?>
    </section>

    <!-- 3. Season tickets -->
    <section class="wiz-step" data-step="2">
        <header><h2>Season tickets</h2><p>The ticket types on sale this season.<?= $sourceTypes ? ' Copied from ' . h((string) $copySource['name']) . ', with their competition rules.' : '' ?></p></header>
        <?php if ($canTickets): ?>
        <div class="hub-panel wiz-card">
            <h3>Types &amp; prices</h3>
            <p class="wiz-sub">Change a price, rename a type, untick to start it hidden, or remove it. Add any new type at the bottom.</p>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="wizTypes">
                <thead><tr><th>Type</th><th class="text-end">Price</th><th class="text-center">On sale</th><th class="text-center">Remove</th></tr></thead>
                <tbody>
                <?php foreach ($sourceTypes as $i => $type): ?>
                    <tr>
                        <td><input type="hidden" name="tt[<?= $i ?>][source_id]" value="<?= (int) $type['id'] ?>"><input type="hidden" name="tt[<?= $i ?>][code]" value="<?= h((string) $type['code']) ?>"><input class="form-control form-control-sm" name="tt[<?= $i ?>][name]" value="<?= h((string) $type['name']) ?>" aria-label="Ticket type name"></td>
                        <td style="min-width:8rem"><?= $cell("tt[$i][price]", $type['price'], $type['name'] . ' season ticket') ?></td>
                        <td class="text-center"><input class="form-check-input" type="checkbox" name="tt[<?= $i ?>][active]" value="1" <?= !empty($type['is_active']) ? 'checked' : '' ?> aria-label="On sale"></td>
                        <td class="text-center"><input class="form-check-input" type="checkbox" name="tt[<?= $i ?>][remove]" value="1" aria-label="Remove this type"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="wizAddType"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add a ticket type</button>
        </div>
        <?php else: ?>
        <div class="alert alert-info">Season ticket types are set up by someone with ticketing access, after the season is created.</div>
        <?php endif; ?>
        <div class="hub-panel wiz-card">
            <h3>Terms &amp; conditions</h3>
            <p class="wiz-sub">Shown to customers at public season ticket checkout. <code>{season_name}</code> and <code>{league_name}</code> fill in automatically.</p>
            <textarea class="form-control" name="season_ticket_terms" rows="10" aria-label="Season ticket terms and conditions"><?= h($seasonTicketTerms) ?></textarea>
        </div>
    </section>

    <!-- 4. Matchday & hospitality -->
    <section class="wiz-step" data-step="3">
        <header><h2>Matchday tickets &amp; hospitality</h2><p>Gate prices and the Big Js hospitality suite.</p></header>
        <?php if ($canTickets): ?>
        <div class="wiz-global"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>These prices are club-wide, not per season. Changes are saved with this season and go live when it becomes current, so today's prices are not affected while you prepare.</div>
        <div class="hub-panel wiz-card">
            <h3>Matchday ticket prices</h3>
            <p class="wiz-sub">Default price for each ticket package. You can still override the price per fixture on Fixture setup.</p>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Package</th><th>Admits</th><th class="text-end">Default price</th></tr></thead>
                <tbody>
                <?php foreach ($matchTicketPackages as $pkg): $pid = (int) $pkg['id']; ?>
                    <tr<?= empty($pkg['is_active']) ? ' class="text-muted"' : '' ?>>
                        <td><?= h((string) $pkg['name']) ?><?= empty($pkg['is_active']) ? ' <span class="badge text-bg-light border">Inactive</span>' : '' ?><div class="small text-muted"><?= h((string) $pkg['package_group']) ?></div></td>
                        <td><?= (int) $pkg['admits_count'] ?></td>
                        <td style="min-width:8rem"><?= $cell("tickets[$pid]", $pkg['default_price'], $pkg['name'] . ' (matchday ticket)') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$matchTicketPackages): ?><tr><td colspan="3" class="text-muted">No ticket packages set up yet.</td></tr><?php endif; ?>
                </tbody></table></div>
        </div>
        <div class="hub-panel wiz-card">
            <h3>Hospitality (per person)</h3>
            <p class="wiz-sub">Cup games are those whose competition name contains &ldquo;Cup&rdquo;.</p>
            <div class="wiz-grid">
                <?= $price('hosp[price_individual_league]', 'Individual — league', $hospitality['price_individual_league'], 'Hospitality individual, league') ?>
                <?= $price('hosp[price_individual_cup]', 'Individual — cup', $hospitality['price_individual_cup'], 'Hospitality individual, cup') ?>
                <?= $price('hosp[price_group_league]', 'Group — league', $hospitality['price_group_league'], 'Hospitality group, league') ?>
                <?= $price('hosp[price_group_cup]', 'Group — cup', $hospitality['price_group_cup'], 'Hospitality group, cup') ?>
            </div>
            <h3 class="mt-4">Corporate package</h3>
            <p class="wiz-sub">Whole package price, by enquiry only.</p>
            <div class="wiz-grid">
                <?= $price('hosp[price_corporate_league]', 'Corporate — league', $hospitality['price_corporate_league'], 'Hospitality corporate, league') ?>
                <?= $price('hosp[price_corporate_cup]', 'Corporate — cup', $hospitality['price_corporate_cup'], 'Hospitality corporate, cup') ?>
            </div>
            <h3 class="mt-4">Places &amp; rules</h3>
            <div class="wiz-grid">
                <?php foreach (['capacity' => 'Places per game', 'individual_max' => 'Individual booking up to', 'group_min' => 'Group booking from', 'corporate_guests' => 'Corporate covers (guests)', 'arrival_minutes_before' => 'Arrive (mins before KO)'] as $key => $label): ?>
                    <div class="wiz-field"><label class="form-label" for="hosp_<?= h($key) ?>"><?= h($label) ?></label><input class="form-control" type="number" min="0" id="hosp_<?= h($key) ?>" name="hosp[<?= h($key) ?>]" value="<?= h((string) $hospitality[$key]) ?>" data-orig="<?= h((string) $hospitality[$key]) ?>" data-label="Hospitality: <?= h($label) ?>"></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info">Matchday ticket and hospitality prices are set by someone with ticketing access.</div>
        <?php endif; ?>
    </section>

    <!-- 5. Other prices -->
    <section class="wiz-step" data-step="4">
        <header><h2>Other prices to check</h2><p>These aren't set per season, so they aren't part of this wizard. Open any that apply after you've created the season.</p></header>
        <div class="hub-panel wiz-card wiz-links">
            <a href="/admin/shop_products.php" target="_blank" rel="noopener"><i class="fa-solid fa-shirt" aria-hidden="true"></i><span>Club shop products &amp; kit<small>Product prices, pre-order window and shipping zones.</small></span></a>
            <a href="/admin/shop_discount_codes.php" target="_blank" rel="noopener"><i class="fa-solid fa-tag" aria-hidden="true"></i><span>Shop discount codes<small>Last season's codes may still be live.</small></span></a>
            <a href="/admin/pos_overview.php" target="_blank" rel="noopener"><i class="fa-solid fa-cash-register" aria-hidden="true"></i><span>Till (POS) product prices<small>Food, drink and merchandise sold on matchdays, and POS discount rules.</small></span></a>
            <a href="/admin/hidden_team_games.php" target="_blank" rel="noopener"><i class="fa-solid fa-ticket" aria-hidden="true"></i><span>Hidden team fundraiser<small>Cost per team and prize percentage are set per game.</small></span></a>
            <a href="/admin/season_ticket_free_codes.php" target="_blank" rel="noopener"><i class="fa-solid fa-key" aria-hidden="true"></i><span>Free season ticket signup codes<small>Codes belong to a season, so create new ones once it exists.</small></span></a>
            <a href="/admin/fixture_tickets.php" target="_blank" rel="noopener"><i class="fa-solid fa-futbol" aria-hidden="true"></i><span>Per-fixture ticket overrides<small>Cup ties or big games priced differently from the default.</small></span></a>
            <a href="/admin/match_deals.php" target="_blank" rel="noopener"><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i><span>Season deals &amp; complimentary sponsors<small>Negotiated amounts are set per sponsor, not by price list.</small></span></a>
        </div>
    </section>

    <!-- 6. Review -->
    <section class="wiz-step" data-step="5">
        <header><h2>Review &amp; create</h2><p>Check what will be created.</p></header>
        <div class="hub-panel wiz-card">
            <h3>Season</h3>
            <ul class="list-unstyled wiz-review mb-0" id="wizReviewSeason"></ul>
        </div>
        <div class="hub-panel wiz-card">
            <h3>Prices you've changed</h3>
            <p class="wiz-sub" id="wizReviewNote"></p>
            <ul class="list-unstyled wiz-review mb-0" id="wizReviewChanges"></ul>
        </div>
        <div class="alert alert-light border small" id="wizReviewTiming"></div>
    </section>

    <div class="wiz-nav">
        <a href="seasons.php" class="btn btn-outline-secondary" id="wizCancel">Cancel</a>
        <span class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="wizBack" hidden><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back</button>
            <button type="button" class="btn btn-brand" id="wizNext">Next <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></button>
            <button type="submit" class="btn btn-brand" id="wizCreate" hidden><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Create season</button>
        </span>
    </div>
</form>

<template id="wizTypeRow">
    <tr>
        <td><input type="hidden" name="tt[__I__][source_id]" value="0"><input class="form-control form-control-sm" name="tt[__I__][name]" placeholder="e.g. Family" aria-label="Ticket type name"></td>
        <td style="min-width:8rem"><div class="input-group input-group-sm"><span class="input-group-text">&pound;</span><input type="text" inputmode="decimal" autocomplete="off" name="tt[__I__][price]" class="form-control text-end" value="0.00" data-money data-orig="" data-label="New ticket type" aria-label="Price"></div></td>
        <td class="text-center"><input class="form-check-input" type="checkbox" name="tt[__I__][active]" value="1" checked aria-label="On sale"></td>
        <td class="text-center"><input class="form-check-input" type="checkbox" name="tt[__I__][remove]" value="1" aria-label="Remove this type"></td>
    </tr>
</template>

<script>
(function () {
    document.documentElement.classList.add('js');
    var form = document.getElementById('seasonWizard');
    var steps = Array.prototype.slice.call(form.querySelectorAll('.wiz-step'));
    var tabs = Array.prototype.slice.call(document.querySelectorAll('#wizSteps li'));
    var back = document.getElementById('wizBack'), next = document.getElementById('wizNext'), create = document.getElementById('wizCreate');
    var current = 0, furthest = 0;

    // Money inputs: digits and one decimal point, shown to 2dp.
    function clean(v) { v = String(v).replace(/[^0-9.]/g, ''); var p = v.split('.'); return p.length > 1 ? p[0] + '.' + p.slice(1).join('').slice(0, 2) : v; }
    function bindMoney(root) {
        root.querySelectorAll('[data-money]').forEach(function (input) {
            if (input.dataset.bound) { return; }
            input.dataset.bound = '1';
            input.addEventListener('input', function () { var pos = input.selectionStart, b = input.value; input.value = clean(b); if (input.value !== b) { input.setSelectionRange(pos - 1, pos - 1); } });
            input.addEventListener('focus', function () { input.select(); });
            input.addEventListener('blur', function () { var n = parseFloat(clean(input.value)); input.value = (isNaN(n) ? 0 : n).toFixed(2); });
        });
    }
    bindMoney(form);

    // Show last season's value under each price field.
    form.querySelectorAll('.wiz-field [data-orig]').forEach(function (input) {
        if (input.dataset.orig !== '' && input.dataset.money !== undefined) {
            var clubWideField = /^(hosp|tickets|pkg)\[/.test(input.name);
            var was = document.createElement('div'); was.className = 'wiz-was';
            was.textContent = (clubWideField ? 'Live now: £' : <?= json_encode($copySource ? 'Last season: £' : 'Default: £') ?>) + input.dataset.orig;
            input.closest('.wiz-field').appendChild(was);
        }
    });

    // Ticket types: add a row.
    var typeBody = document.querySelector('#wizTypes tbody'), typeTpl = document.getElementById('wizTypeRow'), addType = document.getElementById('wizAddType');
    var typeIndex = typeBody ? typeBody.querySelectorAll('tr').length : 0;
    if (addType) {
        addType.addEventListener('click', function () {
            var html = typeTpl.innerHTML.replace(/__I__/g, String(typeIndex++));
            typeBody.insertAdjacentHTML('beforeend', html);
            bindMoney(typeBody);
            var rows = typeBody.querySelectorAll('tr'); rows[rows.length - 1].querySelector('input[name$="[name]"]').focus();
        });
    }

    function money(v) { var n = parseFloat(v); return '£' + (isNaN(n) ? 0 : n).toFixed(2); }
    function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function review() {
        var season = document.getElementById('wizReviewSeason'), changes = document.getElementById('wizReviewChanges');
        var val = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
        var sel = function (id) { var el = document.getElementById(id); return el && el.selectedOptions[0] ? el.selectedOptions[0].textContent.trim() : ''; };
        var rows = [
            ['Name', val('seasonName') || '<em>not set</em>'],
            ['Dates', (val('startDate') || '?') + ' to ' + (val('endDate') || '?')],
            ['League', sel('competitionId')],
            ['Template pack', sel('templatePackId')],
            ['Sponsorship deadline', val('sponsorshipDeadline') || 'none'],
            ['Status', (document.getElementById('isCurrent').checked ? 'Current season' : 'Not current yet') + (document.getElementById('isLocked').checked ? ', locked' : '')]
        ];
        var types = typeBody ? Array.prototype.filter.call(typeBody.querySelectorAll('tr'), function (tr) {
            return tr.querySelector('input[name$="[name]"]').value.trim() !== '' && !tr.querySelector('input[name$="[remove]"]').checked;
        }).length : 0;
        if (typeBody) { rows.push(['Season ticket types', types + ' will be created']); }
        season.innerHTML = rows.map(function (r) { return '<li><strong>' + esc(r[0]) + ':</strong> ' + (r[0] === 'Name' ? r[1] : esc(r[1])) + '</li>'; }).join('');

        var list = [], clubWide = 0;
        form.querySelectorAll('input[data-orig]').forEach(function (input) {
            if (input.closest('tr') && input.name.indexOf('tt[') === 0 && input.dataset.orig === '') { return; }
            if (input.dataset.orig === input.value) { return; }
            var label = input.dataset.label || input.name;
            var club = /^(hosp|tickets|pkg)\[/.test(input.name);
            if (club) { clubWide++; }
            var isMoney = input.dataset.money !== undefined;
            list.push('<li>' + esc(label) + ': <span class="wiz-arrow">' + (isMoney ? money(input.dataset.orig) : esc(input.dataset.orig)) + ' &rarr;</span> <strong>' + (isMoney ? money(input.value) : esc(input.value)) + '</strong>' + (club ? ' <span class="badge text-bg-light border">club-wide</span>' : '') + '</li>');
        });
        changes.innerHTML = list.length ? list.join('') : '<li class="text-muted">No prices changed: everything carries over as it is today.</li>';
        document.getElementById('wizReviewNote').textContent = list.length ? list.length + ' price' + (list.length === 1 ? '' : 's') + ' changed.' : '';
        var timing = document.getElementById('wizReviewTiming');
        var isCurrent = document.getElementById('isCurrent').checked;
        timing.innerHTML = clubWide === 0
            ? 'Nothing here changes today\'s live prices.'
            : (isCurrent ? '<strong>' + clubWide + ' club-wide price' + (clubWide === 1 ? '' : 's') + ' will go live immediately</strong>, because you are making this the current season.'
                         : '<strong>' + clubWide + ' club-wide price' + (clubWide === 1 ? '' : 's') + ' are held back</strong> and go live when you mark this season as current. Today\'s prices are unchanged.');
    }

    function show(i) {
        current = Math.max(0, Math.min(steps.length - 1, i));
        furthest = Math.max(furthest, current);
        steps.forEach(function (s, n) { s.classList.toggle('is-current', n === current); });
        tabs.forEach(function (t, n) { t.classList.toggle('is-current', n === current); t.classList.toggle('is-done', n < furthest && n !== current); });
        back.hidden = current === 0;
        next.hidden = current === steps.length - 1;
        create.hidden = current !== steps.length - 1;
        if (current === steps.length - 1) { review(); }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function valid() {
        var name = document.getElementById('seasonName');
        if (name.value.trim() === '') { show(0); name.focus(); name.classList.add('is-invalid'); return false; }
        name.classList.remove('is-invalid');
        return true;
    }
    next.addEventListener('click', function () { if (current === 0 && !valid()) { return; } show(current + 1); });
    back.addEventListener('click', function () { show(current - 1); });
    tabs.forEach(function (t, n) { t.querySelector('button').addEventListener('click', function () { if (n > 0 && !valid()) { return; } show(n); }); });
    form.addEventListener('submit', function (e) { if (!valid()) { e.preventDefault(); } });
    // Enter inside a field should move on, not submit half-way through.
    form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT' && current < steps.length - 1) { e.preventDefault(); next.click(); } });
    show(0);
})();
</script>
