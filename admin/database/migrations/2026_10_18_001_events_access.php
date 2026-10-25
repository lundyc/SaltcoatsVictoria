<?php
declare(strict_types=1);

// Events module permissions. events_manage implies events_view, events_refund implies events_manage
// (see ACCESS_IMPLIES in lib/access.php). Event door scanning reuses tickets_scan, so there is no
// events_scan capability. View + manage go to the Chairman, Chairman (club oversight), Secretary Ops and
// Treasurer templates; refunds stay with the Administrator template (which bypasses all checks) until the
// club chooses to grant events_refund to someone. Idempotent.

return static function (PDO $pdo): void {
    $capabilities = [
        ['events_view', 'Events — view events, venues and sales (read-only)', 87],
        ['events_manage', 'Events — create and edit events, venues and ticket types', 88],
        ['events_refund', 'Events — refund, cancel and change paid event tickets', 89],
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO capabilities (slug, label, sort_order) VALUES (:slug, :label, :sort)');
    foreach ($capabilities as [$slug, $label, $sort]) {
        $insert->execute([':slug' => $slug, ':label' => $label, ':sort' => $sort]);
    }

    $grant = $pdo->prepare(
        'INSERT IGNORE INTO access_template_capabilities (template_id, capability_id)
         SELECT t.id, c.id FROM access_templates t JOIN capabilities c ON c.slug = :slug
         WHERE t.name = :template'
    );
    foreach (['Chairman', 'Chairman — club oversight', 'Secretary Ops', 'Treasurer'] as $template) {
        foreach (['events_view', 'events_manage'] as $slug) {
            $grant->execute([':slug' => $slug, ':template' => $template]);
        }
    }
};
