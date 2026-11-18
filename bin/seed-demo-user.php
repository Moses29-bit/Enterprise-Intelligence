<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;
use Vantiq\Repository\RoleRepository;
use Vantiq\Repository\UserRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$config = Config::load($root);
if ($config->isProduction()) {
    throw new RuntimeException('The demo account cannot be seeded in production.');
}

$email = getenv('DEMO_USER_EMAIL') ?: 'demo@vantiq.test';
$password = getenv('DEMO_USER_PASSWORD') ?: 'DemoWorkspace2026!';
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < 12 || strlen($password) > 72) {
    throw new RuntimeException('Demo credentials must use a valid email and a 12–72 byte password.');
}

$pdo = Database::connect($config);
$pdo->beginTransaction();
try {
    $organisationQuery = $pdo->prepare('SELECT id FROM organisations WHERE slug = :slug LIMIT 1');
    $organisationQuery->execute(['slug' => 'vantiq-demo-workspace']);
    $organisationId = (int) ($organisationQuery->fetchColumn() ?: 0);

    if ($organisationId === 0) {
        $createOrganisation = $pdo->prepare(
            "INSERT INTO organisations (name, slug, status, created_at, updated_at)
             VALUES (:name, :slug, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $createOrganisation->execute([
            'name' => 'VANTIQ Demo Workspace',
            'slug' => 'vantiq-demo-workspace'
        ]);
        $organisationId = (int) $pdo->lastInsertId();
    }

    $roles = new RoleRepository($pdo);
    $ownerRole = null;
    foreach ($roles->organisationRoles($organisationId) as $role) {
        if ($role['name'] === 'Organisation Owner') {
            $ownerRole = $role;
            break;
        }
    }
    $ownerRoleId = $ownerRole === null
        ? $roles->createOrganisationRoles($organisationId)
        : (int) $ownerRole['id'];
    if ($ownerRoleId === 0) {
        throw new RuntimeException('The demo workspace owner role could not be created.');
    }

    $users = new UserRepository($pdo);
    $user = $users->findByEmail($email);
    if ($user !== null && (int) $user['organisation_id'] !== $organisationId) {
        throw new RuntimeException('The configured demo email is already used by another workspace.');
    }

    if ($user === null) {
        $userId = $users->create(
            $organisationId,
            $email,
            'Demo User',
            password_hash($password, PASSWORD_DEFAULT)
        );
    } else {
        $userId = (int) $user['id'];
        $updateUser = $pdo->prepare(
            "UPDATE users
             SET password_hash = :password_hash, status = 'active', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND organisation_id = :organisation_id"
        );
        $updateUser->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $userId,
            'organisation_id' => $organisationId
        ]);
    }

    $assignment = $pdo->prepare(
        'SELECT 1 FROM user_roles WHERE user_id = :user_id AND role_id = :role_id LIMIT 1'
    );
    $assignment->execute(['user_id' => $userId, 'role_id' => $ownerRoleId]);
    if ($assignment->fetchColumn() === false) {
        $roles->assign($userId, $ownerRoleId);
    }

    $pdo->commit();
    fwrite(STDOUT, "Demo login is ready for {$email}.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
