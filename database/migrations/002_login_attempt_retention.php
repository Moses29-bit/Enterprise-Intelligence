<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = Config::load(dirname(__DIR__, 2));
$pdo = Database::connect($config);
$applied = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
$applied->execute(['version' => '002_login_attempt_retention']);
if ($applied->fetchColumn()) {
    fwrite(STDOUT, "Login attempt retention migration 002 is already applied.\n");
    exit(0);
}

$indexes = $pdo->prepare('SHOW INDEX FROM login_attempts WHERE Key_name = :name');
$indexes->execute(['name' => 'idx_login_attempts_updated']);
if ($indexes->fetch() === false) {
    $pdo->exec('CREATE INDEX idx_login_attempts_updated ON login_attempts (updated_at)');
}

$record = $pdo->prepare(
    'INSERT INTO schema_migrations (version, applied_at)
     VALUES (:version, CURRENT_TIMESTAMP)'
);
$record->execute(['version' => '002_login_attempt_retention']);
fwrite(STDOUT, "Login attempt retention migration 002 completed.\n");
