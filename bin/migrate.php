<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;
use Vantiq\Infrastructure\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$logger = new Logger();

try {
    $pdo = Database::connect(Config::load($root));
    $files = glob($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*');
    if ($files === false) {
        throw new RuntimeException('Unable to discover database migrations.');
    }
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, ['php', 'sql'], true)) {
            continue;
        }

        if ($extension === 'php') {
            runPhpMigration($file, $root);
            continue;
        }

        runSqlMigration($pdo, $file);
    }
} catch (Throwable $exception) {
    $logger->error('Database migration failed', ['exception' => $exception::class]);
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function runPhpMigration(string $file, string $root): void
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $file],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start migration ' . basename($file) . '.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if (is_string($stdout) && $stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($exitCode !== 0) {
        throw new RuntimeException(trim((string) $stderr) ?: basename($file) . ' exited with status ' . $exitCode . '.');
    }
    if (is_string($stderr) && $stderr !== '') {
        fwrite(STDERR, $stderr);
    }
}

function runSqlMigration(PDO $pdo, string $file): void
{
    $version = pathinfo($file, PATHINFO_FILENAME);
    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
    $check->execute(['version' => $version]);
    if ($check->fetchColumn()) {
        fwrite(STDOUT, $version . " is already applied.\n");
        return;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration ' . basename($file) . '.');
    }

    $statements = array_filter(array_map('trim', explode(';', $sql)), static fn (string $statement): bool => $statement !== '');
    foreach ($statements as $statement) {
        if (alreadyApplied($pdo, $statement)) {
            continue;
        }
        $pdo->prepare($statement)->execute();
    }

    $record = $pdo->prepare(
        'INSERT INTO schema_migrations (version, applied_at)
         VALUES (:version, CURRENT_TIMESTAMP)'
    );
    $record->execute(['version' => $version]);
    fwrite(STDOUT, $version . " completed.\n");
}

function alreadyApplied(PDO $pdo, string $statement): bool
{
    if (preg_match('/^CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)/i', $statement, $match)) {
        $check = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $check->execute(['table_name' => $match[1]]);
        return (bool) $check->fetchColumn();
    }

    if (preg_match('/^ALTER TABLE\s+([a-zA-Z0-9_]+)\s+ADD COLUMN\s+([a-zA-Z0-9_]+)/i', $statement, $match)) {
        $check = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
        );
        $check->execute(['table_name' => $match[1], 'column_name' => $match[2]]);
        return (bool) $check->fetchColumn();
    }

    if (preg_match('/^ALTER TABLE\s+([a-zA-Z0-9_]+)\s+ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+([a-zA-Z0-9_]+)/i', $statement, $match)) {
        $check = $pdo->prepare(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
        );
        $check->execute(['table_name' => $match[1], 'index_name' => $match[2]]);
        return (bool) $check->fetchColumn();
    }

    if (preg_match('/^ALTER TABLE\s+([a-zA-Z0-9_]+)\s+ADD CONSTRAINT\s+([a-zA-Z0-9_]+)/i', $statement, $match)) {
        $check = $pdo->prepare(
            'SELECT 1 FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND table_name = :table_name AND constraint_name = :constraint_name'
        );
        $check->execute(['table_name' => $match[1], 'constraint_name' => $match[2]]);
        return (bool) $check->fetchColumn();
    }

    return false;
}
