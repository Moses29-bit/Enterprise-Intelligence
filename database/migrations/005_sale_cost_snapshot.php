<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = Database::connect(Config::load(dirname(__DIR__, 2)));
$version = '005_sale_cost_snapshot';
$check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
$check->execute(['version' => $version]);
if ($check->fetchColumn()) {
    fwrite(STDOUT, "Sale cost snapshot migration 005 is already applied.\n");
    exit(0);
}

$column = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
);
$column->execute(['table_name' => 'sale_items', 'column_name' => 'unit_cost']);
if ((int) $column->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE sale_items ADD COLUMN unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER unit_price');
}

$record = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, CURRENT_TIMESTAMP)');
$record->execute(['version' => $version]);
fwrite(STDOUT, "Sale cost snapshot migration 005 completed.\n");
