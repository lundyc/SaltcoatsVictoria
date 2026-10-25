<?php
declare(strict_types=1);

// Big Js hospitality — prices, capacity, booking rules and who is emailed about new bookings.

$pageHero = [
    'eyebrow' => 'Tickets',
    'title' => 'Hospitality prices & settings',
    'subtitle' => 'What the suite costs, how many can book, and who is told when someone does.',
    'actions' => [
        ['label' => 'Public page', 'href' => '/hospitality', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/hospitality.php';

if (!hub_auth_has_capability('tickets_ops')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage hospitality.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

hospitality_ensure_schema($pdo);
$notice = '';
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            $values = [
                'enabled' => isset($_POST['enabled']) ? '1' : '0',
                'capacity' => (string) max(1, (int) ($_POST['capacity'] ?? 40)),
                'group_min' => (string) max(2, (int) ($_POST['group_min'] ?? 7)),
                'individual_max' => (string) max(1, (int) ($_POST['individual_max'] ?? 6)),
                'arrival_minutes_before' => (string) max(0, (int) ($_POST['arrival_minutes_before'] ?? 90)),
                'corporate_guests' => (string) max(1, (int) ($_POST['corporate_guests'] ?? 8)),
            ];
            foreach (['price_individual_league', 'price_individual_cup', 'price_group_league', 'price_group_cup', 'price_corporate_league', 'price_corporate_cup'] as $k) {
                $values[$k] = number_format(max(0, (float) str_replace(['£', ','], '', (string) ($_POST[$k] ?? '0'))), 2, '.', '');
            }
            hospitality_save_settings($pdo, $values);
            $notice = 'Settings saved. They apply to new bookings straight away.';
        } elseif ($action === 'add_email') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                $pdo->prepare('INSERT IGNORE INTO hospitality_notify_emails (email) VALUES (:e)')->execute([':e' => $email]);
                $notice = $email . ' will now be notified of new bookings.';
            }
        } elseif ($action === 'remove_email') {
            $pdo->prepare('DELETE FROM hospitality_notify_emails WHERE id = :id')->execute([':id' => (int) ($_POST['id'] ?? 0)]);
            $notice = 'Email removed.';
        }
    }
}

$settings = hospitality_settings($pdo);
$emails = hospitality_notify_emails($pdo);
$money = static fn(string $name, string $label, string $hint = ''): string =>
    '<div class="col-6 col-md-4"><label class="form-label" for="f_' . $name . '">' . $label . '</label>'
    . '<div class="input-group"><span class="input-group-text">£</span><input class="form-control" id="f_' . $name . '" name="' . $name . '" inputmode="decimal" value="' . h($settings[$name]) . '"></div>'
    . ($hint !== '' ? '<div class="form-text">' . $hint . '</div>' : '') . '</div>';
$number = static fn(string $name, string $label, int $min, string $hint = ''): string =>
    '<div class="col-6 col-md-4"><label class="form-label" for="f_' . $name . '">' . $label . '</label>'
    . '<input class="form-control" id="f_' . $name . '" type="number" min="' . $min . '" name="' . $name . '" value="' . h($settings[$name]) . '">'
    . ($hint !== '' ? '<div class="form-text">' . $hint . '</div>' : '') . '</div>';
?>
<?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>

<div class="row g-3 hosp-admin">
    <div class="col-xl-8">
        <form method="post" class="vstack gap-3">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">

            <section class="hub-panel hosp-settings-card">
                <header><h2>Online booking</h2><p>Turn this off to stop new bookings from the public page. Existing bookings are not affected.</p></header>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="enabled" <?= $settings['enabled'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enabled">Online booking is open</label>
                </div>
            </section>

            <section class="hub-panel hosp-settings-card">
                <header><h2>Prices per person</h2><p>A game counts as a cup game when its competition name contains “Cup”. Friendlies can’t be booked.</p></header>
                <div class="row g-3">
                    <?= $money('price_individual_league', 'Individual – league') ?>
                    <?= $money('price_individual_cup', 'Individual – cup') ?>
                    <div class="col-md-4 d-none d-md-block"></div>
                    <?= $money('price_group_league', 'Group – league') ?>
                    <?= $money('price_group_cup', 'Group – cup') ?>
                </div>
            </section>

            <section class="hub-panel hosp-settings-card">
                <header><h2>Places &amp; rules</h2><p>How many can book a game, and where an individual booking becomes a group one.</p></header>
                <div class="row g-3">
                    <?= $number('capacity', 'Places per game', 1) ?>
                    <?= $number('individual_max', 'Individual booking up to', 1, 'places') ?>
                    <?= $number('group_min', 'Group booking from', 2, 'places') ?>
                    <?= $number('arrival_minutes_before', 'Guests arrive', 0, 'minutes before kick-off') ?>
                </div>
            </section>

            <section class="hub-panel hosp-settings-card">
                <header><h2>Corporate package</h2><p>Shown on the website for information and handled by enquiry. It is never charged through the online booking form.</p></header>
                <div class="row g-3">
                    <?= $money('price_corporate_league', 'Whole package – league') ?>
                    <?= $money('price_corporate_cup', 'Whole package – cup') ?>
                    <?= $number('corporate_guests', 'Package covers', 1, 'guests') ?>
                </div>
            </section>

            <div class="d-flex flex-wrap align-items-center gap-3">
                <button class="btn btn-brand" type="submit"><i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i>Save settings</button>
                <a class="btn btn-outline-secondary" href="/admin/hospitality.php">Back to bookings</a>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <section class="hub-panel hosp-settings-card">
            <header><h2>Booking notifications</h2><p>These addresses get an email for every paid booking, with names, dietary requirements and contact details.</p></header>
            <ul class="list-group mb-3">
                <?php foreach ($emails as $e): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span class="text-break"><?= h($e['email']) ?></span>
                        <form method="post" class="m-0" onsubmit="return confirm('Stop notifying this address?');"><?= csrf_field() ?><input type="hidden" name="action" value="remove_email"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Remove</button></form>
                    </li>
                <?php endforeach; ?>
                <?php if (!$emails): ?><li class="list-group-item text-muted">No one will be notified. Add an address below.</li><?php endif; ?>
            </ul>
            <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="add_email"><input class="form-control" type="email" name="email" placeholder="name@example.com" aria-label="Email address to notify" required><button class="btn btn-brand" type="submit">Add</button></form>
        </section>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
