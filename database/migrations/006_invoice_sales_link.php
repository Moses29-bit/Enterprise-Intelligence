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
$version = '006_invoice_sales_link';
$check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
$check->execute(['version' => $version]);
if ($check->fetchColumn()) {
    fwrite(STDOUT, "Invoice and sales link migration 006 is already applied.\n");
    exit(0);
}

$column = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
);
$column->execute(['table_name' => 'invoices', 'column_name' => 'sale_id']);
if ((int) $column->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN sale_id BIGINT UNSIGNED DEFAULT NULL AFTER status');
}

$index = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
);
$index->execute(['table_name' => 'invoices', 'index_name' => 'uq_invoices_sale']);
if ((int) $index->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_sale (sale_id)');
}

$constraint = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = :constraint_name'
);
$constraint->execute(['table_name' => 'invoices', 'constraint_name' => 'fk_invoices_sale']);
if ((int) $constraint->fetchColumn() === 0) {
    $pdo->exec(
        'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_sale
         FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL'
    );
}

$record = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, CURRENT_TIMESTAMP)');
$record->execute(['version' => $version]);
fwrite(STDOUT, "Invoice and sales link migration 006 completed.\n");
