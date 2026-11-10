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
$version = '003_business_domains';
$already = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
$already->execute(['version' => $version]);
if ($already->fetchColumn()) {
    fwrite(STDOUT, "Business domain migration 003 is already applied.\n");
    exit(0);
}

$statements = [
    "CREATE TABLE IF NOT EXISTS product_categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_product_categories_org_name (organisation_id, name),
        KEY idx_product_categories_org (organisation_id),
        CONSTRAINT fk_product_categories_organisation
            FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS products (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        branch_id BIGINT UNSIGNED DEFAULT NULL,
        category_id BIGINT UNSIGNED DEFAULT NULL,
        name VARCHAR(180) NOT NULL,
        sku VARCHAR(80) NOT NULL,
        unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        selling_price DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        stock_level INT NOT NULL DEFAULT 0,
        reorder_level INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_products_org_sku (organisation_id, sku),
        KEY idx_products_org_branch (organisation_id, branch_id),
        KEY idx_products_org_category (organisation_id, category_id),
        CONSTRAINT fk_products_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_products_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
        CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS customers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(180) NOT NULL,
        email VARCHAR(254) DEFAULT NULL,
        status ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_customers_org (organisation_id),
        CONSTRAINT fk_customers_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS suppliers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(180) NOT NULL,
        contact_email VARCHAR(254) DEFAULT NULL,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_suppliers_org (organisation_id),
        CONSTRAINT fk_suppliers_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS sales (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        branch_id BIGINT UNSIGNED DEFAULT NULL,
        department_id BIGINT UNSIGNED DEFAULT NULL,
        customer_id BIGINT UNSIGNED DEFAULT NULL,
        sale_date DATETIME NOT NULL,
        subtotal DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        tax DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft', 'posted', 'refunded') NOT NULL DEFAULT 'posted',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_sales_org_date (organisation_id, sale_date),
        KEY idx_sales_org_branch (organisation_id, branch_id),
        KEY idx_sales_org_customer (organisation_id, customer_id),
        CONSTRAINT fk_sales_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
        CONSTRAINT fk_sales_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
        CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS sale_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sale_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(18,2) NOT NULL,
        discount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(18,2) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_sale_items_sale (sale_id),
        KEY idx_sale_items_product (product_id),
        CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS expense_categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        type ENUM('operating', 'administrative', 'inventory', 'other') NOT NULL DEFAULT 'operating',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_expense_category_org_name (organisation_id, name),
        KEY idx_expense_category_org (organisation_id),
        CONSTRAINT fk_expense_categories_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS expenses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        branch_id BIGINT UNSIGNED DEFAULT NULL,
        department_id BIGINT UNSIGNED DEFAULT NULL,
        category_id BIGINT UNSIGNED DEFAULT NULL,
        expense_date DATETIME NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        description VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_expenses_org_date (organisation_id, expense_date),
        KEY idx_expenses_org_branch (organisation_id, branch_id),
        KEY idx_expenses_org_category (organisation_id, category_id),
        CONSTRAINT fk_expenses_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_expenses_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
        CONSTRAINT fk_expenses_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
        CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS invoices (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        customer_id BIGINT UNSIGNED NOT NULL,
        branch_id BIGINT UNSIGNED DEFAULT NULL,
        invoice_number VARCHAR(120) NOT NULL,
        issue_date DATETIME NOT NULL,
        due_date DATETIME NOT NULL,
        total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft', 'issued', 'partial', 'paid', 'overdue') NOT NULL DEFAULT 'issued',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_invoices_org_number (organisation_id, invoice_number),
        KEY idx_invoices_org_customer (organisation_id, customer_id),
        CONSTRAINT fk_invoices_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
        CONSTRAINT fk_invoices_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS invoice_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED DEFAULT NULL,
        description VARCHAR(255) NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(18,2) NOT NULL,
        total DECIMAL(18,2) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_invoice_items_invoice (invoice_id),
        KEY idx_invoice_items_product (product_id),
        CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        CONSTRAINT fk_invoice_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        invoice_id BIGINT UNSIGNED DEFAULT NULL,
        customer_id BIGINT UNSIGNED DEFAULT NULL,
        payment_date DATETIME NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        method VARCHAR(60) NOT NULL,
        reference VARCHAR(120) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_payments_org_date (organisation_id, payment_date),
        KEY idx_payments_invoice (invoice_id),
        KEY idx_payments_customer (customer_id),
        CONSTRAINT fk_payments_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
        CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS stock_movements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        branch_id BIGINT UNSIGNED DEFAULT NULL,
        movement_type ENUM('receipt', 'adjustment', 'transfer', 'sale', 'return') NOT NULL,
        quantity INT NOT NULL,
        unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        reference_type VARCHAR(80) DEFAULT NULL,
        reference_id BIGINT UNSIGNED DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_stock_movements_org_product (organisation_id, product_id, created_at),
        KEY idx_stock_movements_branch (branch_id),
        CONSTRAINT fk_stock_movements_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_stock_movements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_stock_movements_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS purchase_orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        supplier_id BIGINT UNSIGNED NOT NULL,
        branch_id BIGINT UNSIGNED DEFAULT NULL,
        order_date DATETIME NOT NULL,
        expected_date DATETIME DEFAULT NULL,
        status ENUM('draft', 'submitted', 'received', 'closed') NOT NULL DEFAULT 'draft',
        total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_purchase_orders_org_date (organisation_id, order_date),
        CONSTRAINT fk_purchase_orders_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_purchase_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
        CONSTRAINT fk_purchase_orders_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS purchase_order_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        purchase_order_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        quantity INT NOT NULL,
        unit_cost DECIMAL(18,2) NOT NULL,
        total DECIMAL(18,2) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_po_items_order (purchase_order_id),
        KEY idx_po_items_product (product_id),
        CONSTRAINT fk_po_items_purchase_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_po_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS intelligence_insights (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        insight_type VARCHAR(80) NOT NULL,
        title VARCHAR(180) NOT NULL,
        summary TEXT NOT NULL,
        score DECIMAL(5,2) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_intelligence_insights_org (organisation_id, created_at),
        CONSTRAINT fk_intelligence_insights_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS risk_alerts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        category VARCHAR(120) NOT NULL,
        severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
        detected_at DATETIME NOT NULL,
        business_area VARCHAR(120) NOT NULL,
        evidence JSON DEFAULT NULL,
        explanation TEXT NOT NULL,
        suggested_action TEXT NOT NULL,
        reviewer_user_id BIGINT UNSIGNED DEFAULT NULL,
        status ENUM('open', 'assigned', 'reviewing', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
        resolution_notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_risk_alerts_org_status (organisation_id, status, detected_at),
        CONSTRAINT fk_risk_alerts_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_risk_alerts_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS forecasts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        forecast_type VARCHAR(120) NOT NULL,
        forecast_for DATETIME NOT NULL,
        methodology VARCHAR(180) NOT NULL,
        model_version VARCHAR(80) NOT NULL,
        data_coverage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        confidence_interval DECIMAL(5,2) DEFAULT NULL,
        assumptions JSON DEFAULT NULL,
        generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_forecasts_org_date (organisation_id, forecast_for),
        CONSTRAINT fk_forecasts_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS forecast_results (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        forecast_id BIGINT UNSIGNED NOT NULL,
        period_label VARCHAR(80) NOT NULL,
        actual_value DECIMAL(18,2) DEFAULT NULL,
        projected_value DECIMAL(18,2) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_forecast_results_forecast (forecast_id),
        CONSTRAINT fk_forecast_results_forecast FOREIGN KEY (forecast_id) REFERENCES forecasts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS model_versions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        model_name VARCHAR(120) NOT NULL,
        version VARCHAR(60) NOT NULL,
        summary TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_model_versions_org (organisation_id),
        CONSTRAINT fk_model_versions_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS decision_scenarios (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(180) NOT NULL,
        description TEXT DEFAULT NULL,
        status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
        created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_decision_scenarios_org (organisation_id, created_at),
        CONSTRAINT fk_decision_scenarios_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_decision_scenarios_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS scenario_assumptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        scenario_id BIGINT UNSIGNED NOT NULL,
        assumption_key VARCHAR(120) NOT NULL,
        assumption_value JSON NOT NULL,
        comment TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_scenario_assumptions_scenario (scenario_id),
        CONSTRAINT fk_scenario_assumptions_scenario FOREIGN KEY (scenario_id) REFERENCES decision_scenarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS scenario_results (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        scenario_id BIGINT UNSIGNED NOT NULL,
        label VARCHAR(120) NOT NULL,
        metric_name VARCHAR(120) NOT NULL,
        actual_value DECIMAL(18,2) DEFAULT NULL,
        projected_value DECIMAL(18,2) NOT NULL,
        variance DECIMAL(18,2) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_scenario_results_scenario (scenario_id),
        CONSTRAINT fk_scenario_results_scenario FOREIGN KEY (scenario_id) REFERENCES decision_scenarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS imported_files (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        mime_type VARCHAR(80) NOT NULL,
        status ENUM('uploaded', 'validated', 'imported', 'failed') NOT NULL DEFAULT 'uploaded',
        uploaded_by_user_id BIGINT UNSIGNED DEFAULT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_imported_files_org_time (organisation_id, uploaded_at),
        CONSTRAINT fk_imported_files_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_imported_files_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS import_errors (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        import_id BIGINT UNSIGNED NOT NULL,
        row_number INT NOT NULL,
        error_message TEXT NOT NULL,
        raw_data JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_import_errors_import (import_id),
        CONSTRAINT fk_import_errors_import FOREIGN KEY (import_id) REFERENCES imported_files(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organisation_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        type VARCHAR(60) NOT NULL,
        title VARCHAR(180) NOT NULL,
        body TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_notifications_org_user (organisation_id, user_id, created_at),
        CONSTRAINT fk_notifications_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
];

foreach ($statements as $statement) {
    $pdo->exec($statement);
}

$record = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, CURRENT_TIMESTAMP)');
$record->execute(['version' => $version]);

fwrite(STDOUT, "Business domain migration 003 completed.\n");
