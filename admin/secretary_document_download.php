<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('secretary_ops')) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/secretary_documents.php';

$id = (int) ($_GET['id'] ?? 0);
$document = $id > 0 ? secretary_document_get($pdo, $id) : null;

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$path = SECRETARY_DOCUMENT_UPLOAD_DIR . DIRECTORY_SEPARATOR . $document['filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('The stored file could not be found.');
}

$mimeTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
];
$extension = strtolower((string) pathinfo($document['filename'], PATHINFO_EXTENSION));

header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) $document['original_filename']) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
