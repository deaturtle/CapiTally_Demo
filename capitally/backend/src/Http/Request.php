<?php
declare(strict_types=1);

namespace CapiTally\Http;

use CapiTally\Support\AppException;

final class Request
{
    /** Set by the router after authentication. */
    public ?array $user = null;

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pos = strpos($uriPath, '/api/');
        $path = $pos === false ? '/' : substr($uriPath, $pos + 4);   // keep leading slash after /api
        $path = '/' . trim($path, '/');

        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $raw = file_get_contents('php://input', false, null, 0, 1048577);
            if ($raw !== false && $raw !== '') {
                if (strlen($raw) > 1048576) {
                    throw new AppException('Request is too large.', 'PAYLOAD_TOO_LARGE', 413);
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new AppException('Request body must be valid JSON.', 'BAD_JSON', 400);
                }
                $body = $decoded;
            }
        }

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }

        return new self($method, $path, $_GET, $body, $headers);
    }

    public function body(): array
    {
        return $this->body;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function storeId(): int
    {
        return (int) $this->user['store_id'];
    }

    public function userId(): int
    {
        return (int) $this->user['id'];
    }
}
