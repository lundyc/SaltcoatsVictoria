<?php
declare(strict_types=1);

use MyClubHub\Api\{ApiError, Application, Request, Response};

ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/../admin/lib/mobile_api/bootstrap.php';

try {
    $request = Request::fromGlobals();
    $config = MyClubHub\Api\configuration();
    $application = new Application(MyClubHub\Api\connect($config), $config);
    $application->handle($request)->send();
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(16));
    $known = $e instanceof ApiError;
    if (!$known) error_log('Mobile API bootstrap failure request_id=' . $requestId . ' type=' . get_class($e));
    (new Response($known ? $e->status : 503, [
        'error' => ['code' => $known ? $e->errorCode : 'service_unavailable',
            'message' => $known ? $e->getMessage() : 'The service is temporarily unavailable.'],
        'meta' => ['request_id' => $requestId],
    ], ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff', 'X-Request-ID' => $requestId]))->send();
}
