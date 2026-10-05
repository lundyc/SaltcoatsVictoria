<?php
// -------------------------------
// Facebook Auto Poster (no Composer)
// -------------------------------

// Load environment token manually from .env file
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
          die("❌ .env file not found at $envPath\n");
}

// Parse .env file into array
$env = parse_ini_file($envPath);
if (!isset($env['PAGE_ACCESS_TOKEN'])) {
          die("❌ PAGE_ACCESS_TOKEN not found in .env\n");
}

$pageAccessToken = $env['PAGE_ACCESS_TOKEN'];
$pageId = '2412438572150689';
$imagePath = __DIR__ . '/export/latest_wosfl.png';
$caption = "WOSFL Fourth Division Table – Weekly Update ⚽\n#WOSFL #SaltcoatsVictoria";

// Check image exists
if (!file_exists($imagePath)) {
          die("❌ Image not found at $imagePath\n");
}

// Prepare CURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v24.0/{$pageId}/photos");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
          'caption' => $caption,
          'source' => new CURLFile($imagePath),
          'access_token' => $pageAccessToken
]);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

// Logging
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

$logFile = "$logDir/facebook_post.log";
$timestamp = date('Y-m-d H:i:s');
if ($error) {
          file_put_contents($logFile, "[$timestamp] ❌ CURL Error: $error\n", FILE_APPEND);
} else {
          file_put_contents($logFile, "[$timestamp] ✅ Response: $response\n", FILE_APPEND);
}

echo "✅ Done — check logs/facebook_post.log\n";
