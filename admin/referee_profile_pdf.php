<?php
declare(strict_types=1);

/** PDF export of one referee profile from stats.php?tab=referees. */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/referee_pdf.php';

if (!hub_auth_is_authenticated()) { header('Location: /admin/login.php'); exit; }
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) { http_response_code(403); exit('Access denied.'); }

$data = hub_referee_dataset($pdo);
$key = hub_ref_norm((string)($_GET['ref'] ?? ''));
if ($key === '' || !isset($data['refs'][$key])) { http_response_code(404); exit('Referee not found.'); }
$ref = $data['refs'][$key];

if (!class_exists(Dompdf\Dompdf::class)) {
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/project_1/vendor/autoload.php'] as $autoloader) {
        if (is_file($autoloader)) { require_once $autoloader; break; }
    }
}
if (!class_exists(Dompdf\Dompdf::class)) { http_response_code(500); exit('PDF export is unavailable.'); }

$dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
$dompdf->loadHtml(hub_referee_profile_html($ref, $data['baseline'], $data['league']), 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $key), '-');
$dompdf->stream('referee-' . $slug . '-' . date('Ymd') . '.pdf', ['Attachment' => true]);
