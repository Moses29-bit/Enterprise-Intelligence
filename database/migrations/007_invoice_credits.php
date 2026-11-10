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
$version = '007_invoice_credits';
$check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
$check->execute(['version' => $version]);
if ($check->fetchColumn()) {
    fwrite(STDOUT, "Invoice credits migration 007 is already applied.\n");
    exit(0);
}

$column = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
);
foreach ([
    ['invoices', 'tax_amount', 'ALTER TABLE invoices ADD COLUMN tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER total'],
    ['invoice_items', 'unit_cost', 'ALTER TABLE invoice_items ADD COLUMN unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER unit_price'],
] as [$table, $name, $alter]) {
    $column->execute(['table_name' => $table, 'column_name' => $name]);
    if ((int) $column->fetchColumn() === 0) {
        $pdo->exec($alter);
    }
}

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS credit_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    credit_number VARCHAR(120) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    subtotal DECIMAL(18,2) NOT NULL,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(18,2) NOT NULL,
    stock_returned TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credit_notes_org_number (organisation_id, credit_number),
    KEY idx_credit_notes_org_invoice (organisation_id, invoice_id, created_at),
    CONSTRAINT fk_credit_notes_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
    CONSTRAINT fk_credit_notes_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_notes_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_note_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credit_note_id BIGINT UNSIGNED NOT NULL,
    invoice_item_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED DEFAULT NULL,
    quantity INT NOT NULL,
    returned_quantity INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(18,2) NOT NULL,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    subtotal DECIMAL(18,2) NOT NULL,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(18,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_credit_note_items_note (credit_note_id),
    KEY idx_credit_note_items_invoice_item (invoice_item_id),
    CONSTRAINT fk_credit_note_items_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE CASCADE,
    CONSTRAINT fk_credit_note_items_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_note_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    credit_note_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    method VARCHAR(60) NOT NULL,
    reference VARCHAR(120) DEFAULT NULL,
    refunded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_refunds_credit_note (credit_note_id),
    KEY idx_invoice_refunds_org_invoice (organisation_id, invoice_id, refunded_at),
    CONSTRAINT fk_invoice_refunds_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_refunds_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_refunds_credit FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_refunds_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

$record = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, CURRENT_TIMESTAMP)');
$record->execute(['version' => $version]);
fwrite(STDOUT, "Invoice credits migration 007 completed.\n");
