<?php
declare(strict_types=1);

namespace Vantiq\Infrastructure;

use PDOException;
use Throwable;

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

    public function exception(string $message, Throwable $exception, array $context = []): string
    {
        $context['exception'] = $exception::class;

        if ($exception instanceof PDOException) {
            $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
            if (is_string($sqlState) && preg_match('/^[0-9A-Z]{5}$/', $sqlState) === 1) {
                $context['sqlstate'] = $sqlState;
            }
        }

        return $this->correlatedError($message, $context);
    }

    public function correlatedError(string $message, array $context = []): string
    {
        $referenceId = bin2hex(random_bytes(16));
        $context['reference_id'] = $referenceId;
        $this->error($message, $context);
        return $referenceId;
    }
}
