<?php

declare(strict_types=1);

/**
 * Secretary document & record management (Secretary Handbook section 21).
 * Categories mirror the handbook's 13-folder structure. Stored filenames
 * follow the handbook's own naming rule — "YYYY-MM-DD - Subject -
 * Description" — so a folder listing on disk is legible on its own, not
 * just through this page.
 */

const SECRETARY_DOCUMENT_CATEGORIES = [
    'sfa' => '01 SFA',
    'wosfl' => '02 WoSFL',
    'competitions' => '03 Competitions',
    'players' => '04 Players',
    'discipline' => '05 Discipline',
    'fixtures' => '06 Fixtures',
    'match_administration' => '07 Match Administration',
    'committee' => '08 Committee',
    'agm_constitution' => '09 AGM & Constitution',
    'policies_safeguarding' => '10 Policies & Safeguarding',
    'insurance_facilities' => '11 Insurance & Facilities',
    'finance' => '12 Finance correspondence',
    'historic_archive' => '13 Historic Archive',
];

const SECRETARY_DOCUMENT_UPLOAD_DIR = __DIR__ . '/../uploads/secretary_documents';

const SECRETARY_DOCUMENT_ALLOWED_EXTENSIONS = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

function secretary_documents_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS secretary_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(40) NOT NULL DEFAULT 'sfa',
            title VARCHAR(255) NOT NULL,
            description VARCHAR(255) NULL,
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            restricted TINYINT(1) NOT NULL DEFAULT 0,
            uploaded_by BIGINT UNSIGNED NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_secretary_documents_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

/**
 * @return list<array<string, mixed>>
 */
function secretary_documents_list(PDO $pdo): array
{
    secretary_documents_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT * FROM secretary_documents ORDER BY category, title');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function secretary_document_get(PDO $pdo, int $id): ?array
{
    secretary_documents_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM secretary_documents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Builds the handbook's "YYYY-MM-DD - Subject - Description" stored
 * filename from user-supplied text, sanitised for the filesystem.
 */
function secretary_document_build_filename(string $title, string $description, string $extension): string
{
    $sanitize = static function (string $value): string {
        $value = preg_replace('/[^\p{L}\p{N} _-]+/u', '', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    };

    $parts = array_filter([date('Y-m-d'), $sanitize($title), $sanitize($description)]);
    $base = implode(' - ', $parts);
    $base = mb_substr($base, 0, 150);
    $unique = bin2hex(random_bytes(4));

    return $base . ' (' . $unique . ').' . $extension;
}

/**
 * @return array{ok: bool, message: string, id?: int}
 */
function secretary_document_upload(PDO $pdo, array $file, string $category, string $title, string $description, bool $restricted, ?int $userId): array
{
    secretary_documents_ensure_schema($pdo);

    if (!array_key_exists($category, SECRETARY_DOCUMENT_CATEGORIES)) {
        return ['ok' => false, 'message' => 'Select a valid category.'];
    }

    $title = trim($title);
    if ($title === '') {
        return ['ok' => false, 'message' => 'A title is required.'];
    }

    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Choose a file to upload.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'The upload failed (error code ' . (int) $error . ').'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpName)) {
        return ['ok' => false, 'message' => 'Invalid file upload.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size > 15 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'The file must be 15MB or smaller.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmpName) : null;
    if ($finfo) {
        finfo_close($finfo);
    }

    if (!isset(SECRETARY_DOCUMENT_ALLOWED_EXTENSIONS[$mime ?? ''])) {
        return ['ok' => false, 'message' => 'Unsupported file type. Use PDF, Word, Excel, JPG or PNG.'];
    }

    if (!is_dir(SECRETARY_DOCUMENT_UPLOAD_DIR) && !mkdir(SECRETARY_DOCUMENT_UPLOAD_DIR, 0775, true) && !is_dir(SECRETARY_DOCUMENT_UPLOAD_DIR)) {
        return ['ok' => false, 'message' => 'Failed to prepare the document storage folder.'];
    }

    $extension = SECRETARY_DOCUMENT_ALLOWED_EXTENSIONS[$mime];
    $storedFilename = secretary_document_build_filename($title, $description, $extension);
    $destination = SECRETARY_DOCUMENT_UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedFilename;

    if (!move_uploaded_file($tmpName, $destination)) {
        return ['ok' => false, 'message' => 'Failed to store the uploaded file.'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO secretary_documents (category, title, description, filename, original_filename, restricted, uploaded_by)
         VALUES (:category, :title, :description, :filename, :original_filename, :restricted, :uploaded_by)'
    );
    $stmt->execute([
        ':category' => $category,
        ':title' => mb_substr($title, 0, 255),
        ':description' => $description !== '' ? mb_substr($description, 0, 255) : null,
        ':filename' => $storedFilename,
        ':original_filename' => mb_substr((string) ($file['name'] ?? $storedFilename), 0, 255),
        ':restricted' => $restricted ? 1 : 0,
        ':uploaded_by' => $userId,
    ]);

    return ['ok' => true, 'message' => 'Document uploaded.', 'id' => (int) $pdo->lastInsertId()];
}

function secretary_document_delete(PDO $pdo, int $id): bool
{
    secretary_documents_ensure_schema($pdo);
    $document = secretary_document_get($pdo, $id);
    if (!$document) {
        return false;
    }

    $path = SECRETARY_DOCUMENT_UPLOAD_DIR . DIRECTORY_SEPARATOR . $document['filename'];
    if (is_file($path)) {
        @unlink($path);
    }

    $stmt = $pdo->prepare('DELETE FROM secretary_documents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}
