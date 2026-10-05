<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/season_passes.php';

$currentPersonId = member_auth_current_person_id() ?? 0;
$currentPerson = member_auth_current_person();
$currentAccount = member_auth_current_account();
$currentLegacyHolderId = member_auth_current_legacy_holder_id() ?? 0;
$dependents = $currentPersonId > 0 ? getPersonDependents($pdo, $currentPersonId) : [];

$viewingPerson = $currentPerson;
$viewingHolderId = $currentLegacyHolderId;
$requestedPersonId = (int) ($_GET['person_id'] ?? 0);
$requestedHolderId = (int) ($_GET['holder_id'] ?? 0);
if ($requestedPersonId <= 0 && $requestedHolderId > 0) {
    $requestedPersonId = personIdFromLegacyHolderId($pdo, $requestedHolderId) ?? 0;
}
if ($requestedPersonId > 0 && $requestedPersonId !== $currentPersonId) {
    foreach ($dependents as $dependent) {
        if ((int) $dependent['id'] === $requestedPersonId) {
            $viewingPerson = $dependent;
            $viewingHolderId = (int) ($dependent['old_holder_id'] ?? 0);
            break;
        }
    }
}
if (!$viewingPerson) {
    $viewingPerson = $currentPerson;
}

$passes = $viewingPerson ? getSeasonPassesForPerson($pdo, (int) $viewingPerson['id']) : [];
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk');
?>

<style>
    .member-ticket-qr {
        display:inline-grid;
        place-items:center;
        width:fit-content;
        max-width:100%;
        padding:18px;
        border:1px solid rgba(33,20,26,.12);
        border-radius:14px;
        background:#fff;
        box-shadow:0 10px 24px rgba(33,20,26,.08);
    }
    .member-ticket-qr canvas,
    .member-ticket-qr img {
        display:block;
        width:var(--ticket-qr-size, 240px) !important;
        height:var(--ticket-qr-size, 240px) !important;
        max-width:100%;
        max-height:100%;
    }
    .member-ticket-detail-grid {
        display:grid;
        grid-template-columns:minmax(220px, 300px) minmax(0, 1fr);
        gap:2rem;
        align-items:center;
    }
    .member-ticket-qr-panel,
    .member-ticket-info-panel {
        min-width:0;
    }
    .member-ticket-info-panel {
        overflow-wrap:anywhere;
    }
    .member-ticket-info-panel .member-list {
        max-width:100%;
    }
    @media (max-width:575.98px) {
        .member-ticket-card .member-card__body { padding:1rem; }
        .member-ticket-qr {
            --ticket-qr-size:min(280px, calc(100vw - 88px));
            padding:20px;
            border-radius:16px;
        }
    }
    @media (max-width:991.98px) {
        .member-ticket-detail-grid {
            grid-template-columns:1fr;
            gap:1.25rem;
        }
        .member-ticket-info-panel {
            text-align:left;
        }
    }
</style>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Matchday Access</div>
            <h1>Digital Ticket</h1>
            <p>Your season ticket and holder details. Show the QR code at the gate when requested.</p>
        </div>
    </section>

    <?php if (count($dependents) > 0): ?>
        <div class="member-tabs">
            <a href="ticket.php" class="member-tab <?= (int) ($viewingPerson['id'] ?? 0) === $currentPersonId ? 'is-active' : '' ?>"><?= h((string) ($currentPerson['display_name'] ?? $currentHolder['name'])) ?></a>
            <?php foreach ($dependents as $dependent): ?>
                <a href="ticket.php?person_id=<?= (int) $dependent['id'] ?>" class="member-tab <?= (int) ($viewingPerson['id'] ?? 0) === (int) $dependent['id'] ? 'is-active' : '' ?>"><?= h((string) $dependent['display_name']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$passes): ?>
        <div class="member-card"><div class="member-card__body text-center text-muted py-4">No season ticket on file for <?= h((string) ($viewingPerson['display_name'] ?? 'this person')) ?> yet. <a href="/season-tickets">Buy one here</a>.</div></div>
    <?php endif; ?>

    <div class="member-grid member-grid--aside">
        <div class="member-grid">
            <?php foreach ($passes as $pass): ?>
                <?php $verifyUrl = $scheme . '://' . $host . '/verify_ticket.php?token=' . rawurlencode((string) $pass['token']); ?>
                <article class="member-ticket-card">
                    <div class="member-ticket-card__top">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="small text-uppercase opacity-75 fw-bold">Saltcoats Victoria FC</div>
                                <div class="h3 mb-1"><?= h((string) $pass['type_name']) ?> Season Ticket</div>
                                <div class="opacity-75"><?= h((string) $pass['season_name']) ?> · <?= h((string) $pass['entitlement_status']) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="member-card__body">
                        <div class="member-ticket-detail-grid">
                            <div class="member-ticket-qr-panel text-center">
                                <div class="member-ticket-qr" data-qr data-qr-url="<?= h($verifyUrl) ?>" aria-label="Season ticket QR code"></div>
                                <div class="small text-muted mt-2 mb-1">If the QR code won't scan, give the gate this code:</div>
                                <div class="fw-bold font-monospace user-select-all fs-4" style="letter-spacing: 0.1em;"><?= h((string) ($pass['manual_code'] ?? '')) ?></div>
                            </div>
                            <div class="member-ticket-info-panel">
                                <h2 class="h4 mb-3"><?= h((string) ($viewingPerson['display_name'] ?? '')) ?></h2>
                                <div class="member-list">
                                    <div><strong>Ticket type</strong><div class="text-muted small"><?= h((string) $pass['type_name']) ?></div></div>
                                    <div><strong>Holder DOB</strong><div class="text-muted small"><?= !empty($viewingPerson['date_of_birth']) ? h(member_format_date((string) $viewingPerson['date_of_birth'])) : 'Not set' ?></div></div>
                                    <div><strong>Ticket status</strong><div class="text-muted small"><?= h((string) $pass['entitlement_status']) ?></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <aside class="member-card">
            <div class="member-card__body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="member-avatar">
                        <?php if (!empty($viewingPerson['profile_image_path'])): ?><img src="/<?= h((string) $viewingPerson['profile_image_path']) ?>" alt=""><?php else: ?><?= h(member_initials((string) ($viewingPerson['display_name'] ?? ''))) ?><?php endif; ?>
                    </div>
                    <div>
                        <h2><?= h((string) ($viewingPerson['display_name'] ?? '')) ?></h2>
                        <div class="text-muted small">Season ticket holder</div>
                    </div>
                </div>
                <div class="member-list">
                    <div><strong>Email</strong><div class="text-muted small"><?= h((string) ($viewingPerson['email'] ?? 'Not set')) ?></div></div>
                    <div><strong>Phone</strong><div class="text-muted small"><?= h((string) ($viewingPerson['phone'] ?? 'Not set')) ?></div></div>
                    <div><strong>Address</strong><div class="text-muted small"><?= h(member_holder_address($viewingPerson) ?: 'Not set') ?></div></div>
                </div>
            </div>
        </aside>
    </div>
</div>

<script src="/admin/assets/js/vendor/qrcode.min.js"></script>
<script>(() => {
    document.querySelectorAll('[data-qr]').forEach((el) => {
        const size = Math.min(window.matchMedia('(max-width: 575.98px)').matches ? 280 : 240, Math.max(210, el.parentElement ? el.parentElement.clientWidth - 40 : 240));
        el.style.setProperty('--ticket-qr-size', `${size}px`);
        new QRCode(el, { text: el.dataset.qrUrl, width: size, height: size, correctLevel: QRCode.CorrectLevel.H });
    });
})();</script>

<?php require_once __DIR__ . '/footer.php'; ?>
