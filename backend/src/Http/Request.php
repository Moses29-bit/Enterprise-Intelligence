<?php
declare(strict_types=1);

namespace Vantiq\Http;

use JsonException;

final class Request
{
    public const MAX_JSON_BYTES = 65536;
    public const MAX_MULTIPART_BYTES = 16777216;
    private const MAX_CAPTURED_BODY_BYTES = 5242880;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly string $body,
        public readonly string $ip,
        public readonly array $files = [],
        public readonly array $form = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $receivedHeaders = getallheaders();
            if (is_array($receivedHeaders)) {
                foreach ($receivedHeaders as $name => $value) {
                    if (is_string($value)) {
                        $headers[strtolower((string) $name)] = $value;
                    }
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] ??= $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] ??= $_SERVER['CONTENT_TYPE'];
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (preg_match('#(?:^|/)api(?:/|$)#', $path, $match, PREG_OFFSET_CAPTURE)) {
            $start = $match[0][1] + strlen($match[0][0]) - (str_ends_with($match[0][0], '/') ? 1 : 0);
            $path = '/' . ltrim(substr($path, $start), '/');
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $body = '';
        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? '', 2)[0]));
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $contentType !== 'multipart/form-data') {
            $body = file_get_contents('php://input', false, null, 0, self::MAX_CAPTURED_BODY_BYTES + 1) ?: '';
        }

        return new self(
            $method,
            rtrim($path, '/') ?: '/',
            $headers,
            $_GET,
            $body,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_FILES,
            $_POST
        );
    }

    public function json(): array
    {
        if (strlen($this->body) > self::MAX_JSON_BYTES) {
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
        $name = strtolower($name);
        foreach ($this->headers as $headerName => $value) {
            if (is_string($headerName) && strtolower($headerName) === $name && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    public function isJson(): bool
    {
        $contentType = $this->header('content-type');
        return $contentType !== null
            && strtolower(trim(explode(';', $contentType, 2)[0])) === 'application/json';
    }

    public function isMultipart(): bool
    {
        $contentType = $this->header('content-type');
        return $contentType !== null
            && strtolower(trim(explode(';', $contentType, 2)[0])) === 'multipart/form-data';
    }

    public function contentLength(): ?int
    {
        $length = $this->header('content-length');
        if ($length === null || !ctype_digit($length)) {
            return null;
        }
        return (int) $length;
    }
}
