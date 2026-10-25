<?php

declare(strict_types=1);

/**
 * @param array<int, string> $lines
 * @return array<int, string>
 */
function render_normalize_lines(array $lines): array
{
    $normalized = [];

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed !== '') {
            $normalized[] = $trimmed;
        }
    }

    return $normalized;
}

/** Node runtime for the headless-Chrome scripts, kept separate from the system node (see render_capture_image()). */
function render_node_binary(): string
{
    return '/opt/myclubhub-node24/bin/node';
}

/**
 * Print a page to A4 landscape PDF with the shared headless Chrome (render_pdf.js).
 *
 * @return array{ok: bool, error: string, output: array<int, string>}
 */
function render_capture_pdf(string $url, string $outputPath, string $footerText, string $waitSelector = 'body'): array
{
    $command = 'timeout 150 ' . escapeshellarg(render_node_binary()) . ' '
        . escapeshellarg(__DIR__ . '/render_pdf.js')
        . ' ' . escapeshellarg($url)
        . ' ' . escapeshellarg($outputPath)
        . ' ' . escapeshellarg($waitSelector)
        . ' ' . escapeshellarg($footerText);

    $outputLines = [];
    $status = 0;
    exec($command . ' 2>&1', $outputLines, $status);
    $details = render_normalize_lines($outputLines);

    if ($status !== 0 || !is_file($outputPath)) {
        return [
            'ok' => false,
            'error' => 'PDF generation failed.',
            'output' => $details,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'output' => $details,
    ];
}

/**
 * Run the shared Puppeteer renderer and move the generated PNG into place.
 *
 * @return array{ok: bool, error: string, output: array<int, string>}
 */
function render_capture_image(
    string $url,
    string $outputPath,
    string $selector,
    int $width,
    int $height,
    ?string $waitSelector = null,
    int $deviceScaleFactor = 1
): array {
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        return [
            'ok' => false,
            'error' => 'Output directory could not be created.',
            'output' => [],
        ];
    }

    $tempBase = tempnam($outputDir, 'render-');
    if ($tempBase === false) {
        return [
            'ok' => false,
            'error' => 'Temporary export file could not be created.',
            'output' => [],
        ];
    }

    $tempPath = $tempBase . '.png';
    if (!@rename($tempBase, $tempPath)) {
        @unlink($tempBase);
        return [
            'ok' => false,
            'error' => 'Temporary export file could not be prepared.',
            'output' => [],
        ];
    }

    $renderScript = __DIR__ . '/render.js';
    // Uses an isolated Node.js runtime under /opt rather than the system
    // `node` on PATH (Node 18), which several other services on this shared
    // box also depend on (Plesk's own Node hosting support, plus two
    // unrelated live systemd services outside this app). Keeping this app's
    // Node version separate means it can be upgraded — as it was here, to
    // pick up puppeteer-core 25.x's security fixes — without any risk to
    // those other things.
    // Deliberately not existence-checked with is_file()/is_executable(): this
    // path sits outside the FPM pool's open_basedir
    // (/var/www/vhosts/saltcoatsvictoria.co.uk/:/tmp/), so those checks always
    // report false under real web requests regardless of whether the binary
    // is actually there — open_basedir restricts PHP's own filesystem calls,
    // not the process exec() below launches. If this hardcoded path is ever
    // wrong, exec()'s own non-zero exit status (handled below) catches it.
    $nodeBinary = render_node_binary();
    $command = escapeshellarg($nodeBinary) . ' '
        . escapeshellarg($renderScript)
        . ' ' . escapeshellarg($url)
        . ' ' . escapeshellarg($tempPath)
        . ' ' . escapeshellarg($selector)
        . ' ' . escapeshellarg((string) $width)
        . ' ' . escapeshellarg((string) $height)
        . ' ' . escapeshellarg($waitSelector ?? $selector)
        . ' ' . escapeshellarg((string) max(1, $deviceScaleFactor));

    $outputLines = [];
    $status = 0;
    exec($command . ' 2>&1', $outputLines, $status);
    $details = render_normalize_lines($outputLines);

    if ($status !== 0 || !is_file($tempPath)) {
        @unlink($tempPath);
        return [
            'ok' => false,
            'error' => 'Image generation failed.',
            'output' => $details,
        ];
    }

    if (is_file($outputPath) && !@unlink($outputPath)) {
        @unlink($tempPath);
        return [
            'ok' => false,
            'error' => 'Generated image could not replace the existing export.',
            'output' => $details,
        ];
    }

    if (!@rename($tempPath, $outputPath)) {
        @unlink($tempPath);
        return [
            'ok' => false,
            'error' => 'Generated image could not be moved into the export directory.',
            'output' => $details,
        ];
    }

    @chmod($outputPath, 0664);

    return [
        'ok' => true,
        'error' => '',
        'output' => $details,
    ];
}
