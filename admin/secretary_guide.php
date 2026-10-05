<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Emergency Guide',
    'subtitle' => "What do I do if...? Working procedures — verify live SFA/WoSFL rules before acting.",
    'actions' => [],
];

require_once __DIR__ . '/header.php';

$entries = [
    [
        'q' => 'A player signs on Friday night',
        'a' => 'Do not promise Saturday availability. Identify the registration type, complete the current COMET process, confirm the official status and check competition deadlines/eligibility. Tell the manager only when verified.',
        'link' => ['label' => 'Log the registration', 'href' => '/player_registration_edit.php'],
    ],
    [
        'q' => 'A player is sent off',
        'a' => 'Immediately flag unavailable pending verification. Check the current disciplinary procedure and COMET. An automatic suspension may apply immediately — do not wait for an email before checking.',
        'link' => ['label' => 'Log the incident', 'href' => '/discipline_incident.php'],
    ],
    [
        'q' => 'The manager names a player you cannot verify',
        'a' => 'Tell the manager the player is not cleared administratively. Escalate to the competition/SFA contact if necessary. Do not gamble on eligibility.',
    ],
    [
        'q' => 'The opposition asks to change kick-off',
        'a' => 'Record the request; check club availability; follow the league/competition approval process; treat the original fixture as official until approval is confirmed.',
        'link' => ['label' => 'Log the request', 'href' => '/fixture_change_request_edit.php'],
    ],
    [
        'q' => 'The pitch looks unplayable',
        'a' => 'Follow the current inspection/postponement procedure. Arrange the required authorised inspection/decision and notify parties in the correct order.',
    ],
    [
        'q' => 'The referee does not arrive',
        'a' => 'Check the competition rule and contact the designated league/referee contact. Do not invent a replacement process.',
    ],
    [
        'q' => 'COMET is unavailable near a deadline',
        'a' => 'Capture evidence of the outage/time, follow the current SFA support/contingency instruction and contact the relevant authority immediately. Do not assume the deadline is automatically extended.',
    ],
    [
        'q' => 'You receive a disciplinary notice',
        'a' => 'Log receipt time/date, identify response/payment/hearing deadline, send it to the appropriate club officers, create a task and retain the original.',
        'link' => ['label' => 'Log correspondence', 'href' => '/secretary_correspondence_edit.php'],
    ],
    [
        'q' => 'A cup opponent asks if a player is cup-tied',
        'a' => 'Check the specific cup rules and official player history/status. Do not answer from memory.',
    ],
    [
        'q' => 'Someone asks for confidential player information',
        'a' => 'Confirm authority and purpose before disclosure. Share only what is necessary through an appropriate channel.',
    ],
];
?>

<div class="secretary-guide-page">
    <section class="hub-section" aria-labelledby="secretaryGuideTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Reference</div>
                <h2 id="secretaryGuideTitle">Emergency: what do I do if...?</h2>
                <p>The Secretary's own rule: if in doubt, do not guess.</p>
            </div>
        </div>

        <div class="accordion" id="secretaryGuideAccordion">
            <?php foreach ($entries as $index => $entry): ?>
                <div class="accordion-item">
                    <h3 class="accordion-header" id="secretaryGuideHeading<?= $index ?>">
                        <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#secretaryGuideCollapse<?= $index ?>" aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>" aria-controls="secretaryGuideCollapse<?= $index ?>">
                            <?= h($entry['q']) ?>
                        </button>
                    </h3>
                    <div id="secretaryGuideCollapse<?= $index ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" aria-labelledby="secretaryGuideHeading<?= $index ?>" data-bs-parent="#secretaryGuideAccordion">
                        <div class="accordion-body">
                            <p><?= h($entry['a']) ?></p>
                            <?php if (!empty($entry['link'])): ?>
                                <a href="<?= h($entry['link']['href']) ?>" class="btn btn-sm btn-outline-primary"><?= h($entry['link']['label']) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
