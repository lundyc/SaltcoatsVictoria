<?php
// logo-proxy.php
// Fetches a remote club badge, caches it locally, and serves it from same-origin
// so html2canvas can include it in exports.

declare(strict_types=1);

error_reporting(0);

const LOGO_PROXY_MAX_BYTES = 3 * 1024 * 1024; // 3MB is generous for a club badge

$url = isset($_GET['u']) ? (string) $_GET['u'] : '';
if ($url === '') {
          http_response_code(400);
          exit('Missing u');
}

// Basic allowlist (tighten to the domains you actually need)
$allowed = [
          'images.leaguerepublic.com',
          'leaguerepublic.com'
];

$parts = parse_url($url);
$port = $parts['port'] ?? null;
if (
          !isset($parts['scheme'], $parts['host'])
          || strtolower($parts['scheme']) !== 'https'
          || !in_array(strtolower($parts['host']), $allowed, true)
          || ($port !== null && $port !== 443)
) {
          http_response_code(403);
          exit('Blocked host');
}

$cacheDir  = __DIR__ . '/cache/logos';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

$hash = md5($url);
$cacheFileBase = "$cacheDir/$hash";
$cacheFile = null;
foreach (['png', 'jpg', 'gif', 'webp'] as $candidateExt) {
          if (is_file("$cacheFileBase.$candidateExt")) {
                    $cacheFile = "$cacheFileBase.$candidateExt";
                    break;
          }
}

// Serve from cache if present (7 days)
if ($cacheFile !== null && (time() - filemtime($cacheFile) < 60 * 60 * 24 * 7)) {
          $mime = mime_content_type($cacheFile);
          header("Content-Type: $mime");
          header("Cache-Control: public, max-age=604800"); // 7 days
          readfile($cacheFile);
          exit;
}

// Fetch via cURL. Redirects are not followed: the allowlist only vouches for
// the exact host requested, not wherever that host might redirect to, and
// nothing here needs a redirect to work.
$ch = curl_init($url);
curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_FOLLOWLOCATION => false,
          CURLOPT_TIMEOUT => 10,
          CURLOPT_CONNECTTIMEOUT => 5,
          CURLOPT_USERAGENT => 'Mozilla/5.0',
          CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
          CURLOPT_NOPROGRESS => false,
          CURLOPT_PROGRESSFUNCTION => static function ($resource, $downloadSize, $downloaded) {
                    return $downloaded > LOGO_PROXY_MAX_BYTES ? 1 : 0;
          },
]);
$img = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($img === false || $code !== 200 || strlen($img) > LOGO_PROXY_MAX_BYTES) {
          http_response_code(502);
          exit('Fetch failed');
}

// Decode as a real raster image rather than trusting the upstream
// Content-Type or the request URL's extension — this is also what makes it
// impossible for the cached file to end up with an executable extension.
$imageInfo = @getimagesizefromstring($img);
$extByType = [
          IMAGETYPE_PNG => 'png',
          IMAGETYPE_JPEG => 'jpg',
          IMAGETYPE_GIF => 'gif',
          IMAGETYPE_WEBP => 'webp',
];
if ($imageInfo === false || !isset($extByType[$imageInfo[2]])) {
          http_response_code(502);
          exit('Not a supported image');
}
$ext = $extByType[$imageInfo[2]];
$cacheFile = "$cacheFileBase.$ext";

// Save cache
file_put_contents($cacheFile, $img);

// Serve
header('Content-Type: ' . $imageInfo['mime']);
header('Cache-Control: public, max-age=604800');
readfile($cacheFile);
