<?php
declare(strict_types=1);

namespace Vantiq\Config;

use RuntimeException;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $projectRoot): self
    {
        $envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if ($name !== '' && getenv($name) === false) {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                }
            }
        }

        $values = [
            'app_env' => self::env('APP_ENV', 'local'),
            'app_key' => self::env('APP_KEY', ''),
            'app_url' => self::env('APP_URL', ''),
            'session_idle_timeout' => (int) self::env('SESSION_IDLE_TIMEOUT', '1800'),
            'session_absolute_timeout' => (int) self::env('SESSION_ABSOLUTE_TIMEOUT', '43200'),
            'session_cookie_secure' => filter_var(self::env('SESSION_COOKIE_SECURE', 'false'), FILTER_VALIDATE_BOOL),
            'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', self::env('ALLOWED_ORIGINS', ''))))),
            'db_host' => self::env('DB_HOST', '127.0.0.1'),
            'db_port' => (int) self::env('DB_PORT', '3306'),
            'db_name' => self::env('DB_NAME', 'vantiq'),
            'db_user' => self::env('DB_USER', ''),
            'db_password' => self::env('DB_PASSWORD', ''),
            'mail_transport' => self::env('MAIL_TRANSPORT', ''),
            'mail_from' => self::env('MAIL_FROM', ''),
            'reset_url' => self::env('RESET_URL', '')
        ];

        if (strlen($values['app_key']) < 32) {
            throw new RuntimeException('APP_KEY must contain at least 32 characters.');
        }

        if ($values['app_env'] === 'production' && (strlen($values['app_key']) < 32 || $values['app_url'] === '')) {
            throw new RuntimeException('Production requires a strong APP_KEY and APP_URL.');
        }

        if ($values['app_env'] === 'production') {
            if (parse_url($values['app_url'], PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('APP_URL must use HTTPS in production.');
            }
            if ($values['reset_url'] !== '' && parse_url($values['reset_url'], PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('RESET_URL must use HTTPS in production.');
            }
            foreach ($values['allowed_origins'] as $origin) {
                if (parse_url($origin, PHP_URL_SCHEME) !== 'https') {
                    throw new RuntimeException('Production browser origins must use HTTPS.');
                }
            }
        }

        if ($values['session_idle_timeout'] < 60 || $values['session_absolute_timeout'] < $values['session_idle_timeout']) {
            throw new RuntimeException('Session timeout configuration is invalid.');
        }

        return new self($values);
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw new RuntimeException('Unknown configuration key.');
        }

        return $this->values[$key];
    }

    public function isProduction(): bool
    {
        return $this->get('app_env') === 'production';
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false ? $default : $value;
    }
}
