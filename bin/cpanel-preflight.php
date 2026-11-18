<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$errors = [];
$warnings = [];

$requiredPhpExtensions = ['json', 'mbstring', 'pdo', 'pdo_mysql', 'openssl', 'zip'];
foreach ($requiredPhpExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $errors[] = 'Missing PHP extension: ' . $extension;
    }
}

try {
    $config = Config::load($root);
} catch (Throwable $exception) {
    $errors[] = 'Configuration: ' . $exception->getMessage();
    $config = null;
}

if ($config !== null) {
    try {
        $pdo = Database::connect($config);
        $pdo->query('SELECT 1')->fetchColumn();
        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )->fetchColumn();
        fwrite(STDOUT, 'database=connected tables=' . $count . PHP_EOL);

        $storage = (string) $config->get('private_storage_path');
        if (!is_dir($storage)) {
            if (!@mkdir($storage, 0700, true) && !is_dir($storage)) {
                $errors[] = 'Private storage directory cannot be created: ' . $storage;
            }
        }
        if (is_dir($storage) && !is_writable($storage)) {
            $errors[] = 'Private storage directory is not writable: ' . $storage;
        }
        if (is_dir($storage)) {
            fwrite(STDOUT, 'private_storage=writable ' . $storage . PHP_EOL);
        }

        $migrationTable = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
        )->fetchColumn();
        if ((int) $migrationTable === 1) {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            fwrite(STDOUT, 'schema_migrations=' . $applied . PHP_EOL);
        } else {
            $warnings[] = 'schema_migrations table has not been created';
        }
    } catch (Throwable $exception) {
        $errors[] = 'Database connection: ' . $exception::class;
    }

    if (!$config->isProduction()) {
        $warnings[] = 'APP_ENV is not production';
    }
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, 'WARNING ' . $warning . PHP_EOL);
}
foreach ($errors as $error) {
    fwrite(STDERR, 'ERROR ' . $error . PHP_EOL);
}

if ($errors !== []) {
    exit(1);
}
fwrite(STDOUT, "cPanel preflight passed.\n");
