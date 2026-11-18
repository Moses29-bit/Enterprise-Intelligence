<?php
declare(strict_types=1);

namespace Vantiq\Http;

final class Response
{
    public static function success(mixed $data, int $status = 200): void
    {
        self::json([
            'success' => true,
            'data' => $data,
            'error' => null
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        ?array $details = null,
        int $status = 500,
        ?string $referenceId = null
    ): void
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'details' => $details
        ];
        if ($referenceId !== null) {
            $error['reference_id'] = $referenceId;
        }

        self::json([
            'success' => false,
            'data' => null,
            'error' => $error
        ], $status);
    }

    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
