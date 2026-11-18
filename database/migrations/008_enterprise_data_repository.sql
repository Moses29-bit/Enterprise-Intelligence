ALTER TABLE branches ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE branches ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE departments ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE departments ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE product_categories ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE product_categories ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE expense_categories ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE expense_categories ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE products ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE products ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE products ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE products ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE products ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE products ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE customers ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE customers ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE customers ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE customers ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE customers ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE customers ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE suppliers ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE suppliers ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE suppliers ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE suppliers ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE suppliers ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE suppliers ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE sales ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE sales ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE sales ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE sales ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE sales ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE sales ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE expenses ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE expenses ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE expenses ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE expenses ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE expenses ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE expenses ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE invoices ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE invoices ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE invoices ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE invoices ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE invoices ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE invoices ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE payments ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE payments ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE payments ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE payments ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE payments ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE payments ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE stock_movements ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE stock_movements ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE stock_movements ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE stock_movements ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE stock_movements ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE stock_movements ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE purchase_orders ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE purchase_orders ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE purchase_orders ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE purchase_orders ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE purchase_orders ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE purchase_orders ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE credit_notes ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE credit_notes ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE credit_notes ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE credit_notes ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE credit_notes ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE credit_notes ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE invoice_refunds ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE invoice_refunds ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE invoice_refunds ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE invoice_refunds ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE invoice_refunds ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE invoice_refunds ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE invoice_refunds ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
ALTER TABLE intelligence_insights ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE intelligence_insights ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE risk_alerts ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE risk_alerts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE forecasts ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE forecasts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE model_versions ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE model_versions ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE decision_scenarios ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE decision_scenarios ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE imported_files ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE imported_files ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE imported_files ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE notifications ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE notifications ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

UPDATE credit_notes SET created_by = created_by_user_id WHERE created_by IS NULL AND created_by_user_id IS NOT NULL;
UPDATE invoice_refunds SET created_by = created_by_user_id WHERE created_by IS NULL AND created_by_user_id IS NOT NULL;
UPDATE decision_scenarios SET created_by = created_by_user_id WHERE created_by IS NULL AND created_by_user_id IS NOT NULL;
UPDATE imported_files SET created_by = uploaded_by_user_id WHERE created_by IS NULL AND uploaded_by_user_id IS NOT NULL;
UPDATE invoice_refunds SET created_at = refunded_at;
UPDATE imported_files SET created_at = uploaded_at;

ALTER TABLE sale_items ADD COLUMN organisation_id BIGINT UNSIGNED NULL;
UPDATE sale_items item JOIN sales parent ON parent.id = item.sale_id SET item.organisation_id = parent.organisation_id WHERE item.organisation_id IS NULL;
ALTER TABLE sale_items MODIFY COLUMN organisation_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE sale_items ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE sale_items ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE sale_items ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE sale_items ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE sale_items ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE sale_items ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE sale_items ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
UPDATE sale_items item JOIN sales parent ON parent.id = item.sale_id SET item.created_at = parent.created_at;
ALTER TABLE invoice_items ADD COLUMN organisation_id BIGINT UNSIGNED NULL;
UPDATE invoice_items item JOIN invoices parent ON parent.id = item.invoice_id SET item.organisation_id = parent.organisation_id WHERE item.organisation_id IS NULL;
ALTER TABLE invoice_items MODIFY COLUMN organisation_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE invoice_items ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE invoice_items ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE invoice_items ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE invoice_items ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE invoice_items ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE invoice_items ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE invoice_items ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
UPDATE invoice_items item JOIN invoices parent ON parent.id = item.invoice_id SET item.created_at = parent.created_at;
ALTER TABLE credit_note_items ADD COLUMN organisation_id BIGINT UNSIGNED NULL;
UPDATE credit_note_items item JOIN credit_notes parent ON parent.id = item.credit_note_id SET item.organisation_id = parent.organisation_id WHERE item.organisation_id IS NULL;
ALTER TABLE credit_note_items MODIFY COLUMN organisation_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE credit_note_items ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE credit_note_items ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE credit_note_items ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE credit_note_items ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE credit_note_items ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE credit_note_items ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE credit_note_items ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
UPDATE credit_note_items item JOIN credit_notes parent ON parent.id = item.credit_note_id SET item.created_at = parent.created_at;
ALTER TABLE purchase_order_items ADD COLUMN organisation_id BIGINT UNSIGNED NULL;
UPDATE purchase_order_items item JOIN purchase_orders parent ON parent.id = item.purchase_order_id SET item.organisation_id = parent.organisation_id WHERE item.organisation_id IS NULL;
ALTER TABLE purchase_order_items MODIFY COLUMN organisation_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE purchase_order_items ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE purchase_order_items ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE purchase_order_items ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE purchase_order_items ADD COLUMN source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual';
ALTER TABLE purchase_order_items ADD COLUMN source_ref VARCHAR(255) NULL;
ALTER TABLE purchase_order_items ADD COLUMN import_batch_id BIGINT UNSIGNED NULL;
ALTER TABLE purchase_order_items ADD COLUMN data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed';
UPDATE purchase_order_items item JOIN purchase_orders parent ON parent.id = item.purchase_order_id SET item.created_at = parent.created_at;
ALTER TABLE forecast_results ADD COLUMN organisation_id BIGINT UNSIGNED NULL;
UPDATE forecast_results item JOIN forecasts parent ON parent.id = item.forecast_id SET item.organisation_id = parent.organisation_id WHERE item.organisation_id IS NULL;
ALTER TABLE forecast_results MODIFY COLUMN organisation_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE forecast_results ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE forecast_results ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE scenario_assumptions ADD COLUMN organisation_id BIGINT UNSIGNED NULL;
UPDATE scenario_assumptions item JOIN decision_scenarios parent ON parent.id = item.scenario_id SET item.organisation_id = parent.organisation_id WHERE item.organisation_id IS NULL;
ALTER TABLE scenario_assumptions MODIFY COLUMN organisation_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE scenario_assumptions ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE scenario_assumptions ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE scenario_results ADD COLUMN organisation_id BIGINT UNSIGNED NULL;
UPDATE scenario_results item JOIN decision_scenarios parent ON parent.id = item.scenario_id SET item.organisation_id = parent.organisation_id WHERE item.organisation_id IS NULL;
ALTER TABLE scenario_results MODIFY COLUMN organisation_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE scenario_results ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE scenario_results ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE import_errors ADD COLUMN organisation_id BIGINT UNSIGNED NULL;
UPDATE import_errors item JOIN imported_files parent ON parent.id = item.import_id SET item.organisation_id = parent.organisation_id WHERE item.organisation_id IS NULL;
ALTER TABLE import_errors MODIFY COLUMN organisation_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE import_errors ADD COLUMN created_by BIGINT UNSIGNED NULL;
ALTER TABLE import_errors ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE departments ADD UNIQUE KEY uq_departments_organisation_id (organisation_id, id);
ALTER TABLE products ADD UNIQUE KEY uq_products_organisation_id (organisation_id, id);
ALTER TABLE customers ADD UNIQUE KEY uq_customers_organisation_id (organisation_id, id);
ALTER TABLE suppliers ADD UNIQUE KEY uq_suppliers_organisation_id (organisation_id, id);
ALTER TABLE sales ADD UNIQUE KEY uq_sales_organisation_id (organisation_id, id);
ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_organisation_id (organisation_id, id);
ALTER TABLE credit_notes ADD UNIQUE KEY uq_credit_notes_organisation_id (organisation_id, id);
ALTER TABLE purchase_orders ADD UNIQUE KEY uq_purchase_orders_organisation_id (organisation_id, id);
ALTER TABLE forecasts ADD UNIQUE KEY uq_forecasts_organisation_id (organisation_id, id);
ALTER TABLE decision_scenarios ADD UNIQUE KEY uq_decision_scenarios_organisation_id (organisation_id, id);
ALTER TABLE imported_files ADD UNIQUE KEY uq_imported_files_organisation_id (organisation_id, id);
ALTER TABLE sale_items ADD KEY idx_sale_items_organisation (organisation_id, sale_id);
ALTER TABLE invoice_items ADD KEY idx_invoice_items_organisation (organisation_id, invoice_id);
ALTER TABLE credit_note_items ADD KEY idx_credit_note_items_organisation (organisation_id, credit_note_id);
ALTER TABLE purchase_order_items ADD KEY idx_purchase_order_items_organisation (organisation_id, purchase_order_id);
ALTER TABLE forecast_results ADD KEY idx_forecast_results_organisation (organisation_id, forecast_id);
ALTER TABLE scenario_assumptions ADD KEY idx_scenario_assumptions_organisation (organisation_id, scenario_id);
ALTER TABLE scenario_results ADD KEY idx_scenario_results_organisation (organisation_id, scenario_id);
ALTER TABLE import_errors ADD KEY idx_import_errors_organisation (organisation_id, import_id);
ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_organisation FOREIGN KEY (organisation_id, sale_id) REFERENCES sales(organisation_id, id) ON DELETE CASCADE;
ALTER TABLE invoice_items ADD CONSTRAINT fk_invoice_items_organisation FOREIGN KEY (organisation_id, invoice_id) REFERENCES invoices(organisation_id, id) ON DELETE CASCADE;
ALTER TABLE credit_note_items ADD CONSTRAINT fk_credit_note_items_organisation FOREIGN KEY (organisation_id, credit_note_id) REFERENCES credit_notes(organisation_id, id) ON DELETE CASCADE;
ALTER TABLE purchase_order_items ADD CONSTRAINT fk_purchase_order_items_organisation FOREIGN KEY (organisation_id, purchase_order_id) REFERENCES purchase_orders(organisation_id, id) ON DELETE CASCADE;
ALTER TABLE forecast_results ADD CONSTRAINT fk_forecast_results_organisation FOREIGN KEY (organisation_id, forecast_id) REFERENCES forecasts(organisation_id, id) ON DELETE CASCADE;
ALTER TABLE scenario_assumptions ADD CONSTRAINT fk_scenario_assumptions_organisation FOREIGN KEY (organisation_id, scenario_id) REFERENCES decision_scenarios(organisation_id, id) ON DELETE CASCADE;
ALTER TABLE scenario_results ADD CONSTRAINT fk_scenario_results_organisation FOREIGN KEY (organisation_id, scenario_id) REFERENCES decision_scenarios(organisation_id, id) ON DELETE CASCADE;
ALTER TABLE import_errors ADD CONSTRAINT fk_import_errors_organisation FOREIGN KEY (organisation_id, import_id) REFERENCES imported_files(organisation_id, id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    employee_number VARCHAR(80) NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    email VARCHAR(254) NULL,
    department_id BIGINT UNSIGNED NULL,
    branch_id BIGINT UNSIGNED NULL,
    status ENUM('active', 'inactive', 'terminated') NOT NULL DEFAULT 'active',
    source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255) NULL,
    import_batch_id BIGINT UNSIGNED NULL,
    data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employees_org_number (organisation_id, employee_number),
    UNIQUE KEY uq_employees_org_id (organisation_id, id),
    KEY idx_employees_org_status (organisation_id, status),
    CONSTRAINT fk_employees_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
    CONSTRAINT fk_employees_department FOREIGN KEY (organisation_id, department_id) REFERENCES departments(organisation_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_employees_branch FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    account_code VARCHAR(80) NOT NULL,
    name VARCHAR(180) NOT NULL,
    account_type ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'GHS',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255) NULL,
    import_batch_id BIGINT UNSIGNED NULL,
    data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ledger_accounts_org_code (organisation_id, account_code),
    UNIQUE KEY uq_ledger_accounts_org_id (organisation_id, id),
    KEY idx_ledger_accounts_org_type (organisation_id, account_type, status),
    CONSTRAINT fk_ledger_accounts_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    ledger_account_id BIGINT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    journal_reference VARCHAR(120) NULL,
    description VARCHAR(255) NOT NULL,
    debit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    credit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    source ENUM('manual', 'upload', 'integration') NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255) NULL,
    import_batch_id BIGINT UNSIGNED NULL,
    data_quality_status ENUM('unreviewed', 'validated', 'rejected') NOT NULL DEFAULT 'unreviewed',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ledger_entries_org_date (organisation_id, entry_date),
    KEY idx_ledger_entries_org_account_date (organisation_id, ledger_account_id, entry_date),
    CONSTRAINT fk_ledger_entries_organisation FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ledger_entries_account FOREIGN KEY (organisation_id, ledger_account_id) REFERENCES ledger_accounts(organisation_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_ledger_entries_nonnegative CHECK (debit >= 0 AND credit >= 0),
    CONSTRAINT chk_ledger_entries_single_sided CHECK (NOT (debit > 0 AND credit > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
