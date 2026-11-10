<?php
declare(strict_types=1);

namespace Vantiq\Infrastructure;

final class Logger
{
    public function error(string $message, array $context = []): void
    {
        error_log(json_encode([
            'level' => 'error',
            'message' => $message,
            'context' => $context,
            'time' => gmdate(DATE_ATOM)
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'VANTIQ error');
    }
}
