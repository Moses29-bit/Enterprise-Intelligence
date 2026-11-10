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
try {
    $alreadyApplied = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
    $alreadyApplied->execute(['version' => '001_authentication']);
    if ($alreadyApplied->fetchColumn()) {
        fwrite(STDOUT, "Authentication schema migration 001 is already applied.\n");
        exit(0);
    }
} catch (PDOException $exception) {
    if (($exception->errorInfo[1] ?? null) !== 1146) {
        throw $exception;
    }
}

$schema = file_get_contents(dirname(__DIR__) . '/vantiq_schema.sql');
if ($schema === false) {
    throw new RuntimeException('Unable to read the VANTIQ schema.');
}

foreach (explode(';', $schema) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$record = $pdo->prepare(
    'INSERT INTO schema_migrations (version, applied_at)
     VALUES (:version, CURRENT_TIMESTAMP)'
);
$record->execute(['version' => '001_authentication']);
fwrite(STDOUT, "Authentication schema migration 001 completed.\n");
