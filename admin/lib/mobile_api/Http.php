<?php
declare(strict_types=1);

namespace MyClubHub\Api;

final class ApiError extends \RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public array $headers = [])
    {
        parent::__construct($message);
    }
}

final class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public string $body = '',
        public string $ip = '127.0.0.1',
        public bool $secure = true,
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        $headers['content-type'] = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $headers['authorization'] = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
            throw new ApiError(413, 'payload_too_large', 'The request body exceeds 16 KiB.');
        }
        $body = file_get_contents('php://input', false, null, 0, 16385);
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return new self(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), (string) $path,
            $_GET, $headers, $body === false ? '' : $body, (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
    }

    public function json(): array
    {
        if (strlen($this->body) > 16384) {
            throw new ApiError(413, 'payload_too_large', 'The request body exceeds 16 KiB.');
        }
        if (strtolower(trim(explode(';', $this->headers['content-type'] ?? '')[0])) !== 'application/json') {
            throw new ApiError(415, 'unsupported_media_type', 'Use Content-Type: application/json.');
        }
        try {
            $decoded = json_decode($this->body, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError(400, 'invalid_json', 'Provide a valid JSON object.');
        }
        if (!$decoded instanceof \stdClass) {
            throw new ApiError(400, 'invalid_json', 'Provide a JSON object, not an array or scalar.');
        }
        return (array) $decoded;
    }

    public function bearer(): string
    {
        if (!preg_match('/^Bearer (mch_a_[a-f0-9]{64})$/iD', $this->headers['authorization'] ?? '', $m)) {
            throw new ApiError(401, 'unauthenticated', 'A valid access token is required.', ['WWW-Authenticate' => 'Bearer']);
        }
        return $m[1];
    }
}

final class Response
{
    public function __construct(public int $status, public array $body, public array $headers = []) {}

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $key => $value) {
            header($key . ': ' . $value);
        }
        if ($this->status !== 204) {
            echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }
        exit;
    }
}

final class Input
{
    public static function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw new ApiError(422, 'validation_failed', 'The request contains unsupported fields.');
        }
    }

    public static function text(array $data, string $key, int $max, bool $required = true, bool $trim = true): string
    {
        $value = array_key_exists($key, $data) ? $data[$key] : '';
        if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new ApiError(422, 'validation_failed', "Invalid {$key}.");
        }
        $value = $trim ? trim($value) : $value;
        if ($required && $value === '') {
            throw new ApiError(422, 'validation_failed', "{$key} is required.");
        }
        return $value;
    }

    public static function integer(mixed $value, string $name, int $min = 1, int $max = 2147483647): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]{1,10}$/D', (string) $value)
            || (int) $value < $min || (int) $value > $max) {
            throw new ApiError(422, 'validation_failed', "Invalid {$name}.");
        }
        return (int) $value;
    }

    public static function pagination(array $query): array
    {
        $page = self::integer($query['page'] ?? 1, 'page', 1, 10000);
        $limit = self::integer($query['per_page'] ?? 20, 'per_page', 1, 50);
        return [$page, $limit, ($page - 1) * $limit];
    }
}
