<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;
use Vantiq\Repository\RoleRepository;
use Vantiq\Repository\UserRepository;
use Vantiq\Http\Validator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = Config::load(dirname(__DIR__));
$email = mb_strtolower(trim((string) getenv('PLATFORM_ADMIN_EMAIL')));
$name = trim((string) getenv('PLATFORM_ADMIN_NAME'));
$password = (string) getenv('PLATFORM_ADMIN_PASSWORD');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($name) > 180
    || strlen($password) < 12 || strlen($password) > Validator::PASSWORD_MAX_BYTES) {
    fwrite(STDERR, "Set PLATFORM_ADMIN_EMAIL, PLATFORM_ADMIN_NAME, and a 12 to 72 byte PLATFORM_ADMIN_PASSWORD in the process environment.\n");
    exit(2);
}

$pdo = Database::connect($config);
$users = new UserRepository($pdo);
if ($users->findByEmail($email) !== null) {
    fwrite(STDERR, "An account already uses that email address.\n");
    exit(2);
}

$pdo->beginTransaction();
try {
    $userId = $users->createPlatformAdmin($email, $name, password_hash($password, PASSWORD_DEFAULT));
    (new RoleRepository($pdo))->assignPlatformAdmin($userId);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

fwrite(STDOUT, "Platform administrator provisioned.\n");
