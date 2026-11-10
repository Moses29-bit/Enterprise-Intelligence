<?php
declare(strict_types=1);

namespace Vantiq\Http;

use JsonException;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly string $body,
        public readonly string $ip
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] ??= $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] ??= $_SERVER['CONTENT_TYPE'];
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (preg_match('#(?:^|/)api(?:/|$)#', $path, $match, PREG_OFFSET_CAPTURE)) {
            $start = $match[0][1] + strlen($match[0][0]) - (str_ends_with($match[0][0], '/') ? 1 : 0);
            $path = '/' . ltrim(substr($path, $start), '/');
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rtrim($path, '/') ?: '/',
            self::normalizeHeaders($headers),
            $_GET,
            file_get_contents('php://input') ?: '',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );
    }

    public function json(): array
    {
        if (strlen($this->body) > 65536) {
            throw new ApiException(413, 'payload_too_large', 'The request body is too large.');
        }

        if ($this->body === '') {
            return [];
        }

        try {
            $decoded = json_decode($this->body, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'invalid_json', 'The request body must be valid JSON.');
        }

        if (!$decoded instanceof \stdClass) {
            throw new ApiException(400, 'invalid_json', 'The JSON request body must be an object.');
        }

        return get_object_vars($decoded);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $normalized[strtolower((string) $name)] = $value;
            }
        }

        return $normalized;
    }
}
