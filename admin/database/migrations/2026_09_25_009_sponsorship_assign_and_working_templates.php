<?php
declare(strict_types=1);

// 1. sponsorship_assign — a real capability for "assign sponsors to players and
//    set the sponsorship deadline". Until now this was hub_auth_is_sponsorship_editor(),
//    a single configured email address that no template could grant.
// 2. Two working templates: Sponsorship Manager, and Media & Merchandise.
// 3. Two club roles (labels): Club Photographer, Merchandising — each only
//    SUGGESTS the Media & Merchandise template; they grant nothing themselves.
// Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("INSERT IGNORE INTO capabilities (slug, label, sort_order) VALUES
        ('sponsorship_assign', 'Sponsorship — assign sponsors to players and set the sponsorship deadline', 36)");

    $templates = [
        ['sponsorship_manager', 'Sponsorship Manager', ['sponsorship', 'sponsorship_assign']],
        ['media_merchandise', 'Media & Merchandise', ['content_social', 'shop', 'website']],
    ];
    foreach ($templates as [$slug, $name, $caps]) {
        $pdo->prepare('INSERT IGNORE INTO access_templates (slug, name, bypass_all, is_system, sort_order) VALUES (:s, :n, 0, 0, 50)')
            ->execute([':s' => $slug, ':n' => $name]);
        $templateId = (int) $pdo->query('SELECT id FROM access_templates WHERE slug = ' . $pdo->quote($slug))->fetchColumn();
        $link = $pdo->prepare('INSERT IGNORE INTO access_template_capabilities (template_id, capability_id)
            SELECT :t, id FROM capabilities WHERE slug = :c');
        foreach ($caps as $cap) {
            $link->execute([':t' => $templateId, ':c' => $cap]);
        }
    }

    $mediaId = (int) $pdo->query("SELECT id FROM access_templates WHERE slug = 'media_merchandise'")->fetchColumn();
    foreach ([['Club Photographer', 20], ['Merchandising', 21]] as [$name, $sort]) {
        $pdo->prepare("INSERT IGNORE INTO club_roles (name, department, sort_order, access_template_id) VALUES (:n, 'other', :s, :t)")
            ->execute([':n' => $name, ':s' => $sort, ':t' => $mediaId]);
    }
};
