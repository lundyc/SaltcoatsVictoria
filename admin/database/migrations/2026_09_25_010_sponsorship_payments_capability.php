<?php
declare(strict_types=1);

// sponsorship_payments — "record and correct what sponsors have paid".
// Narrower than finance_manage: it does NOT include refunds, Stripe payment
// links, matchday income or ticket money. It implies sponsorship (see
// ACCESS_IMPLIES in lib/access.php) and is part of the Sponsorship Manager
// template, whose holder marks sponsor payments up as part of the job.
// Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("INSERT IGNORE INTO capabilities (slug, label, sort_order) VALUES
        ('sponsorship_payments', 'Sponsorship — record and correct sponsor payments (no refunds or Stripe)', 37)");
    $pdo->exec("INSERT IGNORE INTO access_template_capabilities (template_id, capability_id)
        SELECT t.id, c.id FROM access_templates t JOIN capabilities c ON c.slug = 'sponsorship_payments'
        WHERE t.slug = 'sponsorship_manager'");
};
