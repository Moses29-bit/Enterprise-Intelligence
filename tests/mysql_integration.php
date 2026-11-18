<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Application;
use Vantiq\Config\Config;
use Vantiq\Http\Request;
use Vantiq\Infrastructure\Database;
use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\AuditRepository;
use Vantiq\Repository\LedgerRepository;
use Vantiq\Repository\OutboxRepository;
use Vantiq\Repository\PasswordResetRepository;
use Vantiq\Security\SessionManager;
use Vantiq\Service\OutboxDispatcher;

$config = new Config([
    'app_env' => 'test',
    'app_key' => bin2hex(random_bytes(32)),
    'app_url' => 'http://localhost',
    'session_idle_timeout' => 1800,
    'session_absolute_timeout' => 43200,
    'session_cookie_secure' => false,
    'allowed_origins' => [],
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => (int) (getenv('DB_PORT') ?: 3306),
    'db_name' => getenv('DB_NAME') ?: 'vantiq',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_password' => getenv('DB_PASSWORD') ?: '',
    'mail_transport' => '',
    'mail_from' => '',
    'reset_url' => ''
]);
$pdo = Database::connect($config);
$session = new SessionManager($config);
$session->start();
$application = new Application($config, $pdo, new Logger());
$email = 'phase1-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
$organisationId = null;

$request = static function (string $method, string $path, array $body = [], ?string $csrf = null) use ($application): array {
    $headers = ['content-type' => 'application/json'];
    if ($csrf !== null) {
        $headers['x-csrf-token'] = $csrf;
    }

    ob_start();
    $application->handle(new Request(
        $method,
        $path,
        $headers,
        [],
        $method === 'GET' ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        '127.0.0.1'
    ));
    $response = ob_get_clean();
    return [http_response_code(), json_decode($response ?: '', true, 512, JSON_THROW_ON_ERROR)];
};

try {
    $csrf = $session->csrfToken();
    [$status, $registration] = $request('POST', '/auth/register', [
        'organisation_name' => 'VANTIQ MySQL integration',
        'name' => 'Integration Test',
        'email' => $email,
        'password' => 'MyTestPassword!2026'
    ], $csrf);
    if ($status !== 200 || !in_array('Organisation Owner', $registration['data']['user']['roles'] ?? [], true)) {
        throw new RuntimeException('MySQL-backed registration failed: ' . json_encode($registration));
    }
    $organisationId = (int) $registration['data']['user']['organisation']['id'];

    $outbox = new OutboxRepository($pdo);
    (new LedgerRepository($pdo, new AuditRepository($pdo), $outbox))->createProduct(
        $organisationId,
        (int) $registration['data']['user']['id'],
        [
            'name' => 'Outbox integration fixture',
            'sku' => 'OUTBOX-' . strtoupper(bin2hex(random_bytes(5))),
            'unit_cost' => '2.00',
            'selling_price' => '5.00',
            'reorder_level' => 1,
        ]
    );
    $dispatchResult = (new OutboxDispatcher($outbox, new Logger()))->runOnce(10, $organisationId);
    if ($dispatchResult !== ['published' => 1, 'failed' => 0]) {
        throw new RuntimeException('The committed product was not processed by the tenant-scoped outbox worker.');
    }
    $notifications = $outbox->notificationsAfter($organisationId, 0);
    if (count($notifications) !== 1
        || $notifications[0]['event_type'] !== 'ProductCreated'
        || !in_array('operations', $notifications[0]['modules'], true)) {
        throw new RuntimeException('The MySQL outbox event was not published as a tenant-scoped notification.');
    }
    [$status, $current] = $request('GET', '/auth/me');
    if ($status !== 200 || ($current['data']['user']['email'] ?? null) !== $email) {
        throw new RuntimeException('MySQL-backed current-user lookup failed.');
    }

    [$status, $passwordChange] = $request('POST', '/auth/change-password', [
        'current_password' => 'MyTestPassword!2026',
        'new_password' => 'MyUpdatedPassword!2026'
    ], $registration['data']['csrfToken']);
    if ($status !== 200 || !($passwordChange['data']['changed'] ?? false)) {
        throw new RuntimeException('MySQL-backed password change failed.');
    }

    [$status, $login] = $request('POST', '/auth/login', [
        'email' => $email,
        'password' => 'MyUpdatedPassword!2026'
    ], $passwordChange['data']['csrfToken']);
    if ($status !== 200 || ($login['data']['user']['email'] ?? null) !== $email) {
        throw new RuntimeException('MySQL-backed login failed.');
    }

    $rawToken = bin2hex(random_bytes(32));
    $resetTokens = new PasswordResetRepository($pdo);
    $resetTokens->create(
        (int) $registration['data']['user']['id'],
        hash('sha256', $rawToken),
        gmdate('Y-m-d H:i:s', time() + 1800)
    );
    $pdo->beginTransaction();
    $consumedUserId = $resetTokens->consume(hash('sha256', $rawToken), gmdate('Y-m-d H:i:s'));
    $pdo->commit();
    if ($consumedUserId !== (int) $registration['data']['user']['id']
        || $resetTokens->consume(hash('sha256', $rawToken), gmdate('Y-m-d H:i:s')) !== null) {
        throw new RuntimeException('MySQL password reset token was not single-use.');
    }

    [$status, $logout] = $request('POST', '/auth/logout', [], $login['data']['csrfToken']);
    if ($status !== 200 || !($logout['data']['loggedOut'] ?? false)) {
        throw new RuntimeException('MySQL-backed logout failed.');
    }

    [$status] = $request('GET', '/auth/me');
    if ($status !== 401) {
        throw new RuntimeException('Logout did not invalidate the authenticated session.');
    }

    echo "PASS MySQL-backed auth, transactional product outbox, tenant notification publication, and logout\n";
} finally {
    if ($organisationId !== null) {
        $delete = $pdo->prepare('DELETE FROM organisations WHERE id = :id');
        $delete->execute(['id' => $organisationId]);
    }
    $session->destroy();
}
