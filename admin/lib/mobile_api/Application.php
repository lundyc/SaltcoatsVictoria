<?php
declare(strict_types=1);

namespace MyClubHub\Api;

final class Application
{
    public const ROUTES = [
        ['GET', '/health', 'health', false],
        ['GET', '/openapi.json', 'specification', false],
        ['POST', '/auth/login', 'login', false],
        ['POST', '/auth/refresh', 'refresh', false],
        ['POST', '/auth/logout', 'logout', true],
        ['POST', '/auth/logout-all', 'logoutAll', true],
        ['GET', '/club', 'club', false],
        ['GET', '/seasons', 'seasons', false],
        ['GET', '/fixtures', 'fixtures', false],
        ['GET', '/fixtures/{id}', 'fixture', false],
        ['GET', '/results', 'results', false],
        ['GET', '/news', 'news', false],
        ['GET', '/news/{slug}', 'article', false],
        ['GET', '/players', 'players', false],
        ['GET', '/players/{id}', 'player', false],
        ['GET', '/table', 'table', false],
        ['GET', '/me', 'profile', true],
        ['PATCH', '/me', 'updateProfile', true],
        ['GET', '/me/season-passes', 'passes', true],
        ['GET', '/me/tickets', 'tickets', true],
        ['GET', '/me/tickets/{id}', 'ticket', true],
        ['GET', '/me/orders', 'orders', true],
        ['GET', '/me/orders/{id}', 'order', true],
        ['GET', '/me/sessions', 'sessions', true],
        ['DELETE', '/me/sessions/{session}', 'revokeSession', true],
    ];

    public function __construct(private \PDO $pdo, private array $config) {}

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(16));
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Request-ID' => $requestId, 'Vary' => 'Origin',
            'Referrer-Policy' => 'no-referrer'];
        try {
            if (!$request->secure) throw new ApiError(400, 'https_required', 'Use HTTPS for all API requests.');
            $origin = $request->headers['origin'] ?? '';
            if ($origin !== '') {
                if (!in_array($origin, array_merge([$this->config['base_url']], $this->config['origins']), true)) {
                    throw new ApiError(403, 'origin_not_allowed', 'This browser origin is not allowed.');
                }
                $headers['Access-Control-Allow-Origin'] = $origin;
            }
            $auth = new Auth($this->pdo, $requestId);
            $auth->rateLimit('request-ip:' . $request->ip, 300, 60);
            $path = rtrim($request->path, '/');
            if (!str_starts_with($path, '/api/v1/')) throw new ApiError(404, 'not_found', 'API endpoint not found.');
            $path = substr($path, strlen('/api/v1'));
            $matches = [];
            foreach (self::ROUTES as [$method, $pattern, $action, $private]) {
                $regex = str_replace(['\{id\}','\{slug\}','\{session\}'], ['([0-9]{1,10})','([a-z0-9][a-z0-9-]{0,189})','([a-f0-9]{32})'], preg_quote($pattern, '~'));
                if (preg_match('~^' . $regex . '$~D', $path, $params)) $matches[] = [$method, $action, $private, $params[1] ?? null];
            }
            if (!$matches) throw new ApiError(404, 'not_found', 'API endpoint not found.');
            $allow = implode(', ', [...array_column($matches, 0), 'OPTIONS']);
            if ($request->method === 'OPTIONS') {
                return new Response(204, [], $headers + ['Allow' => $allow, 'Access-Control-Allow-Methods' => $allow,
                    'Access-Control-Allow-Headers' => 'Authorization, Content-Type', 'Access-Control-Max-Age' => '600']);
            }
            $match = null;
            foreach ($matches as $candidate) if ($candidate[0] === $request->method) $match = $candidate;
            if (!$match) throw new ApiError(405, 'method_not_allowed', 'Method not allowed.', ['Allow' => $allow]);
            [, $action, $private, $param] = $match;
            $actor = $private ? $auth->authenticate($request->bearer()) : null;
            if (in_array($action, ['health','specification','club','profile','updateProfile','fixture','player','article','ticket','order','login','refresh','logout','logoutAll','revokeSession'], true)) {
                Input::only($request->query, []);
            }
            $resources = null;
            $resource = function () use (&$resources): Resources {
                return $resources ??= new Resources($this->pdo, $this->config);
            };
            $id = static fn() => Input::integer($param, 'id');
            $result = match ($action) {
                'health' => $this->health(),
                'specification' => json_decode((string) file_get_contents(__DIR__ . '/openapi.json'), true, 64, JSON_THROW_ON_ERROR),
                'login' => $auth->login($request->json(), $request->ip),
                'refresh' => $auth->refresh($request->json(), $request->ip),
                'logout' => $auth->revoke($actor, $actor['session_id']),
                'logoutAll' => $auth->revoke($actor, null, true),
                'revokeSession' => $auth->revoke($actor, $param),
                'club' => $resource()->club(),
                'seasons' => $resource()->seasons($request->query),
                'fixtures' => $resource()->fixtures($request->query, false),
                'results' => $resource()->fixtures($request->query, true),
                'fixture' => $resource()->fixture($id()),
                'news' => $resource()->news($request->query),
                'article' => $resource()->article($param),
                'players' => $resource()->players($request->query),
                'player' => $resource()->player($id()),
                'table' => $resource()->table($request->query),
                'profile' => $resource()->profile($actor),
                'updateProfile' => $resource()->updateProfile($actor, $request->json(), $auth),
                'passes' => $resource()->passes($actor, $request->query),
                'tickets' => $resource()->tickets($actor, $request->query),
                'ticket' => $resource()->ticket($actor, $id()),
                'orders' => $resource()->orders($actor, $request->query),
                'order' => $resource()->order($actor, $id()),
                'sessions' => $resource()->sessions($actor, $request->query),
            };
            if (random_int(1, 100) === 1) {
                try { $auth->maintenance(); } catch (\Throwable) { error_log('Mobile API cleanup failed; request_id=' . $requestId); }
            }
            if ($result === null) return new Response(204, [], $headers);
            if ($action === 'specification') return new Response(200, $result, $headers);
            $body = in_array($action, ['seasons','fixtures','results','news','players','passes','tickets','orders','sessions'], true)
                ? $result : ['data' => $result];
            $body['meta'] = ($body['meta'] ?? []) + ['request_id' => $requestId];
            return new Response($action === 'login' ? 201 : 200, $body, $headers);
        } catch (ApiError $e) {
            return new Response($e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
                'meta' => ['request_id' => $requestId]], $headers + $e->headers);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('Mobile API failure request_id=' . $requestId . ' type=' . get_class($e) . ' code=' . $e->getCode()
                . ' file=' . basename($e->getFile()) . ':' . $e->getLine());
            return new Response(500, ['error' => ['code' => 'internal_error', 'message' => 'An unexpected error occurred.'],
                'meta' => ['request_id' => $requestId]], $headers);
        }
    }

    private function health(): array
    {
        $this->pdo->query('SELECT id FROM mobile_api_sessions LIMIT 1');
        return ['status' => 'ok', 'version' => 'v1'];
    }
}
