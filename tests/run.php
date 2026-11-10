<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Http\ApiException;
use Vantiq\Http\Validator;
use Vantiq\Repository\AuditRepository;
use Vantiq\Repository\LoginAttemptRepository;
use Vantiq\Repository\LoginHistoryRepository;
use Vantiq\Repository\OrganisationRepository;
use Vantiq\Repository\PasswordResetRepository;
use Vantiq\Repository\RoleRepository;
use Vantiq\Repository\UserRepository;
use Vantiq\Security\AuthMiddleware;
use Vantiq\Security\SessionManager;
use Vantiq\Service\AccountService;
use Vantiq\Service\AuthService;
use Vantiq\Service\PasswordResetDelivery;

$pdo = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(<<<'SQL'
CREATE TABLE organisations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL, created_at TEXT, updated_at TEXT
);
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NULL REFERENCES organisations(id),
  email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, full_name TEXT NOT NULL,
  status TEXT NOT NULL, session_version INTEGER NOT NULL DEFAULT 0, last_login_at TEXT,
  created_at TEXT, updated_at TEXT
);
CREATE TABLE roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NULL REFERENCES organisations(id),
  name TEXT NOT NULL, description TEXT, is_system INTEGER NOT NULL DEFAULT 0, created_at TEXT
);
CREATE TABLE permissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, description TEXT NOT NULL, created_at TEXT
);
CREATE TABLE user_roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE, created_at TEXT,
  UNIQUE(user_id, role_id)
);
CREATE TABLE role_permissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE, created_at TEXT,
  UNIQUE(role_id, permission_id)
);
CREATE TABLE audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NULL REFERENCES organisations(id),
  user_id INTEGER NULL REFERENCES users(id), action_type TEXT NOT NULL, entity_name TEXT NOT NULL,
  details TEXT NULL, created_at TEXT
);
CREATE TABLE password_reset_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT
);
CREATE TABLE login_attempts (
  bucket_hash TEXT PRIMARY KEY, failed_attempts INTEGER NOT NULL, window_started_at TEXT NOT NULL,
  locked_until TEXT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE login_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NULL REFERENCES organisations(id),
  user_id INTEGER NULL REFERENCES users(id), email TEXT NOT NULL, status TEXT NOT NULL,
  ip_hash TEXT NOT NULL, created_at TEXT
);
SQL);

$permissionInsert = $pdo->prepare('INSERT INTO permissions (code, description) VALUES (?, ?)');
foreach ([
    'users.manage', 'finance.read', 'operations.read', 'decisions.manage',
    'risks.read', 'reports.read', 'platform.users.manage'
] as $permission) {
    $permissionInsert->execute([$permission, $permission]);
}
$pdo->exec(
    "INSERT INTO roles (organisation_id, name, description, is_system, created_at)
     VALUES (NULL, 'Platform Super Administrator', 'CLI provisioned', 1, CURRENT_TIMESTAMP)"
);
$pdo->exec(
    "INSERT INTO role_permissions (role_id, permission_id, created_at)
     SELECT r.id, p.id, CURRENT_TIMESTAMP FROM roles r, permissions p
     WHERE r.name = 'Platform Super Administrator' AND p.code = 'platform.users.manage'"
);

$config = new Config([
    'app_env' => 'test',
    'app_key' => str_repeat('a', 64),
    'app_url' => 'http://localhost',
    'session_idle_timeout' => 1800,
    'session_absolute_timeout' => 43200,
    'session_cookie_secure' => false,
    'allowed_origins' => [],
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'vantiq',
    'db_user' => '',
    'db_password' => '',
    'mail_transport' => 'test',
    'mail_from' => 'no-reply@example.test',
    'reset_url' => 'http://localhost/reset-password'
]);
$session = new SessionManager($config);
$session->start();
$users = new UserRepository($pdo);
$organisations = new OrganisationRepository($pdo);
$roles = new RoleRepository($pdo);
$audit = new AuditRepository($pdo);
$attempts = new LoginAttemptRepository($pdo);
$history = new LoginHistoryRepository($pdo);
$resetTokens = new PasswordResetRepository($pdo);
$mailer = new class implements PasswordResetDelivery {
    public array $messages = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $email, string $resetUrl): void
    {
        $this->messages[] = [$email, $resetUrl];
    }
};
$auth = new AuthService(
    $pdo, $config, $session, $users, $organisations, $roles, $audit,
    $attempts, $history, $resetTokens, $mailer
);
$results = [];
$test = static function (string $name, callable $callback) use (&$results): void {
    try {
        $callback();
        $results[] = [$name, true, ''];
    } catch (Throwable $exception) {
        $results[] = [$name, false, $exception::class . ': ' . $exception->getMessage()];
    }
};
$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectApiError = static function (callable $callback, int $status) use ($assert): ApiException {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->status === $status, 'Unexpected API status: ' . $exception->status);
        return $exception;
    }
    throw new RuntimeException('Expected an API exception.');
};

$test('Organisation registration, owner role and password hashing', static function () use ($auth, $users, $roles, $pdo, $assert): void {
    $result = $auth->register('Northwind Group', 'Riley Owner', 'owner@example.test', 'StrongPassword!42', '127.0.0.1');
    $assert($result['user']['organisation']['name'] === 'Northwind Group');
    $assert(in_array('Organisation Owner', $result['user']['roles'], true));
    $assert(in_array('users.manage', $result['user']['permissions'], true));
    $user = $users->findByEmail('owner@example.test');
    $assert($user !== null && $user['password_hash'] !== 'StrongPassword!42');
    $assert(password_verify('StrongPassword!42', $user['password_hash']));
    $assert(!$roles->hasPermission((int) $user['id'], 'platform.users.manage'));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1);
});

$test('Registration rejects duplicate email addresses', static function () use ($auth, $expectApiError): void {
    $expectApiError(
        static fn () => $auth->register('Another Group', 'Other User', 'owner@example.test', 'AnotherPass!42', '127.0.0.1'),
        409
    );
});

$test('Successful and unsuccessful login regenerates the session id', static function () use ($auth, $session, $expectApiError, $assert): void {
    $session->start();
    $previousSessionId = session_id();
    $login = $auth->login('owner@example.test', 'StrongPassword!42', '127.0.0.2');
    $assert($login['user']['email'] === 'owner@example.test');
    $assert(session_id() !== $previousSessionId, 'Authentication did not regenerate the session identifier.');
    $error = $expectApiError(
        static fn () => $auth->login('owner@example.test', 'wrong password', '127.0.0.3'),
        401
    );
    $assert($error->errorCode === 'invalid_credentials');
});

$test('Login attempt throttling applies by account and address', static function () use ($auth, $expectApiError, $assert): void {
    for ($index = 0; $index < 5; $index++) {
        $expectApiError(
            static fn () => $auth->login('unknown@example.test', 'wrong password', '192.0.2.44'),
            401
        );
    }
    $diagnostic = $GLOBALS['pdo']->query(
        'SELECT failed_attempts, locked_until FROM login_attempts ORDER BY failed_attempts DESC LIMIT 1'
    )->fetch();
    $assert($diagnostic !== false, 'No login attempt buckets were recorded.');
    $assert($diagnostic['locked_until'] !== null, 'The bucket did not store its lock expiry: ' . json_encode($diagnostic));
    $error = $expectApiError(
        static fn () => $auth->login('unknown@example.test', 'wrong password', '192.0.2.44'),
        429
    );
    $assert($error->errorCode === 'login_throttled');
});

$test('SQL injection-shaped login input cannot authenticate', static function () use ($auth, $expectApiError): void {
    $expectApiError(
        static fn () => $auth->login("' OR 1=1 --@example.test", 'StrongPassword!42', '192.0.2.90'),
        401
    );
});

$test('Logout destroys the current session', static function () use ($auth, $session, $assert): void {
    $user = $GLOBALS['users']->findByEmail('owner@example.test');
    $session->start();
    $_SESSION['user_id'] = (int) $user['id'];
    $auth->logout($user);
    $assert(session_status() === PHP_SESSION_NONE, 'The PHP session remained active.');
    $assert(!isset($_SESSION['user_id']), 'The session identity was not cleared.');
});

$test('CSRF token rejects missing or mismatched values', static function () use ($session, $expectApiError, $assert): void {
    $session->start();
    $token = $session->csrfToken();
    $expectApiError(static fn () => $session->assertCsrf('invalid'), 419);
    $session->assertCsrf($token);
    $session->destroy();
});

$test('Expired sessions are invalidated', static function () use ($config, $session, $users, $organisations, $assert): void {
    $user = $users->findByEmail('owner@example.test');
    $session->start();
    $_SESSION = [
        'user_id' => (int) $user['id'],
        'auth_version' => (int) $user['session_version'],
        'issued_at' => time() - 10,
        'last_activity' => time() - 3600
    ];
    $middleware = new AuthMiddleware($config, $session, $users, $organisations);
    try {
        $middleware->requireUser();
        throw new RuntimeException('Expected expired session rejection.');
    } catch (ApiException $exception) {
        $assert($exception->status === 401);
    }
    $assert(session_status() === PHP_SESSION_NONE);
});

$test('Role checks prevent non-owner account management', static function () use ($pdo, $users, $roles, $assert): void {
    $owner = $users->findByEmail('owner@example.test');
    $organisationId = (int) $owner['organisation_id'];
    $statement = $pdo->prepare('SELECT id FROM roles WHERE organisation_id = ? AND name = ?');
    $statement->execute([$organisationId, 'Financial Analyst']);
    $analystRoleId = (int) $statement->fetchColumn();
    $analystId = $users->create($organisationId, 'analyst@example.test', 'Finance Analyst', password_hash('AnalystPassword!42', PASSWORD_DEFAULT));
    $roles->assign($analystId, $analystRoleId);
    $assert($roles->hasPermission($analystId, 'finance.read'));
    $assert(!$roles->hasPermission($analystId, 'users.manage'));
});

$test('Account suspension and activation revoke existing sessions', static function () use ($config, $session, $users, $roles, $organisations, $audit, $pdo, $assert): void {
    $analyst = $users->findByEmail('analyst@example.test');
    $owner = $users->findByEmail('owner@example.test');
    $service = new AccountService($pdo, $users, $roles, $audit);
    $session->start();
    $_SESSION = [
        'user_id' => (int) $analyst['id'],
        'auth_version' => (int) $analyst['session_version'],
        'issued_at' => time(),
        'last_activity' => time()
    ];
    $service->updateStatus($owner, (int) $analyst['id'], 'suspended', null);
    $assert($users->findById((int) $analyst['id'])['status'] === 'suspended');
    try {
        (new AuthMiddleware($config, $session, $users, $organisations))->requireUser();
        throw new RuntimeException('A suspended account retained an authenticated session.');
    } catch (ApiException $exception) {
        $assert($exception->status === 401);
    }

    $service->updateStatus($owner, (int) $analyst['id'], 'active', null);
    $assert($users->findById((int) $analyst['id'])['status'] === 'active');
});

$test('Password changes invalidate sessions created with the prior version', static function () use ($auth, $config, $session, $users, $organisations, $assert): void {
    $user = $users->findByEmail('owner@example.test');
    $priorVersion = (int) $user['session_version'];
    $session->start();
    $_SESSION = [
        'user_id' => (int) $user['id'],
        'auth_version' => $priorVersion,
        'issued_at' => time(),
        'last_activity' => time()
    ];
    $auth->changePassword($user, 'StrongPassword!42', 'ChangedPassword!42');
    $fresh = $users->findByEmail('owner@example.test');
    $assert(password_verify('ChangedPassword!42', $fresh['password_hash']));
    $assert((int) $fresh['session_version'] === $priorVersion + 1);
    $_SESSION['auth_version'] = $priorVersion;
    try {
        (new AuthMiddleware($config, $session, $users, $organisations))->requireUser();
        throw new RuntimeException('A stale session version remained authenticated.');
    } catch (ApiException $exception) {
        $assert($exception->status === 401);
    }
});

$test('Organisation isolation blocks cross-tenant account changes', static function () use ($auth, $users, $roles, $audit, $pdo, $expectApiError, $assert): void {
    $auth->register('Southwind Group', 'South Owner', 'south@example.test', 'SouthPassword!42', '127.0.0.5');
    $target = $users->findByEmail('south@example.test');
    $actor = $users->findByEmail('owner@example.test');
    $service = new AccountService($pdo, $users, $roles, $audit);
    $expectApiError(
        static fn () => $service->updateStatus($actor, (int) $target['id'], 'suspended', null),
        404
    );
    $assert($users->findById((int) $target['id'])['status'] === 'active');
});

$test('Role updates are tenant-scoped and preserve the last organisation owner', static function () use ($users, $roles, $audit, $pdo, $expectApiError, $assert): void {
    $owner = $users->findByEmail('owner@example.test');
    $analyst = $users->findByEmail('analyst@example.test');
    $otherOwner = $users->findByEmail('south@example.test');
    $service = new AccountService($pdo, $users, $roles, $audit);
    $organisationId = (int) $owner['organisation_id'];
    $roleStatement = $pdo->prepare('SELECT id FROM roles WHERE organisation_id = ? AND name = ?');
    $roleStatement->execute([$organisationId, 'Operations Manager']);
    $operationsRoleId = (int) $roleStatement->fetchColumn();

    $result = $service->updateRoles($owner, (int) $analyst['id'], [$operationsRoleId]);
    $assert($result['roles'] === ['Operations Manager']);
    $assert($roles->hasPermission((int) $analyst['id'], 'operations.read'));

    $expectApiError(
        static fn () => $service->updateRoles($owner, (int) $analyst['id'], [(int) $pdo->query(
            "SELECT id FROM roles WHERE organisation_id = " . (int) $otherOwner['organisation_id'] . " AND name = 'Organisation Owner'"
        )->fetchColumn()]),
        422
    );

    $ownerRole = $roles->organisationRoles($organisationId);
    $ownerRoleId = (int) array_values(array_filter($ownerRole, static fn (array $role): bool => $role['name'] === 'Organisation Owner'))[0]['id'];
    $expectApiError(static fn () => $service->updateRoles($owner, (int) $owner['id'], []), 422);
    $assert($roles->userHasOrganisationRole((int) $owner['id'], $organisationId, $ownerRoleId));
});

$test('Invalid input validation returns field errors', static function () use ($expectApiError): void {
    $expectApiError(static fn () => Validator::email(['email' => "' OR 1=1 --"]), 422);
    $expectApiError(static fn () => Validator::password(['password' => 'short']), 422);
});

$test('Password validation prevents bcrypt input truncation', static function () use ($expectApiError, $assert): void {
    $password = str_repeat('a', Validator::PASSWORD_MAX_BYTES);
    $assert(Validator::password(['password' => $password]) === $password);
    $expectApiError(
        static fn () => Validator::password(['password' => $password . 'a']),
        422
    );
});

$test('Password reset rejects expired and reused tokens', static function () use ($auth, $users, $resetTokens, $session, $expectApiError, $assert): void {
    $user = $users->findByEmail('owner@example.test');
    $expiredToken = str_repeat('a', 64);
    $resetTokens->create((int) $user['id'], hash('sha256', $expiredToken), gmdate('Y-m-d H:i:s', time() - 60));
    $expectApiError(static fn () => $auth->resetPassword($expiredToken, 'ResetPassword!42'), 422);

    $token = bin2hex(random_bytes(32));
    $resetTokens->create((int) $user['id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 1800));
    $auth->resetPassword($token, 'ResetPassword!42');
    $assert(password_verify('ResetPassword!42', $users->findByEmail('owner@example.test')['password_hash']));
    $assert(session_status() === PHP_SESSION_ACTIVE && $session->csrfToken() !== '');
    $assert(!isset($_SESSION['user_id']));
    $expectApiError(static fn () => $auth->resetPassword($token, 'AnotherReset!42'), 422);
});

$test('Password reset delivery records only a hashed, expiring token', static function () use ($auth, $mailer, $pdo, $assert): void {
    $auth->requestPasswordReset('owner@example.test');
    $assert(count($mailer->messages) === 1, 'Expected one reset email attempt.');
    parse_str((string) parse_url($mailer->messages[0][1], PHP_URL_QUERY), $query);
    $assert(isset($query['token']) && strlen($query['token']) === 64, 'Reset link token is missing.');
    $statement = $pdo->prepare('SELECT token_hash, expires_at FROM password_reset_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([(int) $pdo->query("SELECT id FROM users WHERE email = 'owner@example.test'")->fetchColumn()]);
    $stored = $statement->fetch();
    $assert($stored['token_hash'] !== $query['token'], 'The raw token was stored.');
    $assert(strtotime($stored['expires_at']) > time(), 'Reset token expiration was not in the future.');
});

$test('Password reset does not claim delivery when mail is unconfigured', static function () use ($pdo, $config, $session, $users, $organisations, $roles, $audit, $attempts, $history, $resetTokens, $expectApiError, $assert): void {
    $unconfiguredMailer = new class implements PasswordResetDelivery {
        public function isConfigured(): bool { return false; }
        public function send(string $email, string $resetUrl): void
        {
            throw new RuntimeException('An unconfigured transport must never send.');
        }
    };
    $service = new AuthService(
        $pdo, $config, $session, $users, $organisations, $roles, $audit,
        $attempts, $history, $resetTokens, $unconfiguredMailer
    );
    $before = (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn();
    $expectApiError(static fn () => $service->requestPasswordReset('owner@example.test'), 503);
    $after = (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn();
    $assert($before === $after, 'An unconfigured delivery created a reset token.');
});

$test('Tenant role assignment query cannot attach another organisation role', static function () use ($users, $roles, $pdo, $assert): void {
    $ownerA = $users->findByEmail('owner@example.test');
    $ownerB = $users->findByEmail('south@example.test');
    $otherRole = $pdo->prepare('SELECT id FROM roles WHERE organisation_id = ? AND name = ?');
    $otherRole->execute([(int) $ownerB['organisation_id'], 'Operations Manager']);
    $otherRoleId = (int) $otherRole->fetchColumn();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?');
    $statement->execute([(int) $ownerA['id'], $otherRoleId]);
    $assert((int) $statement->fetchColumn() === 0);
});

$failed = 0;
foreach ($results as [$name, $passed, $message]) {
    fwrite(STDOUT, ($passed ? 'PASS' : 'FAIL') . ' ' . $name . ($passed ? '' : ' — ' . $message) . PHP_EOL);
    if (!$passed) {
        $failed++;
    }
}
fwrite(STDOUT, sprintf("\n%d tests, %d failures\n", count($results), $failed));
exit($failed === 0 ? 0 : 1);
