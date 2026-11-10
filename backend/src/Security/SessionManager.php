<?php
declare(strict_types=1);

namespace Vantiq\Security;

use Vantiq\Config\Config;
use Vantiq\Http\ApiException;

final class SessionManager
{
    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('VANTIQSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->config->get('session_cookie_secure') || $this->config->isProduction(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_start();

        $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_destroy();
    }

    public function rotate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
    }

    public function csrfToken(): string
    {
        return (string) ($_SESSION['_csrf'] ?? '');
    }

    public function assertCsrf(?string $token): void
    {
        $expected = $this->csrfToken();
        if ($token === null || $expected === '' || !hash_equals($expected, $token)) {
            throw new ApiException(419, 'csrf_failed', 'The security token is missing or expired.');
        }
    }
}
