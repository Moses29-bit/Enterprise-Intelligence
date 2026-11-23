CREATE SCHEMA IF NOT EXISTS vantiq;
SET search_path TO vantiq, public;

CREATE TABLE schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE migration_checkpoints (
    source_table VARCHAR(128) PRIMARY KEY,
    source_fingerprint CHAR(64) NOT NULL,
    source_record_count BIGINT NOT NULL CHECK (source_record_count >= 0),
    imported_record_count BIGINT NOT NULL DEFAULT 0 CHECK (imported_record_count >= 0),
    completed BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE organisations (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive','suspended')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE users (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT REFERENCES organisations(id) ON DELETE CASCADE,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive','suspended')),
    session_version INTEGER NOT NULL DEFAULT 0,
    last_login_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_users_organisation_status ON users(organisation_id, status);

CREATE TABLE roles (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT REFERENCES organisations(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255),
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, name)
);
CREATE INDEX idx_roles_organisation ON roles(organisation_id);
CREATE TABLE permissions (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE user_roles (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (user_id, role_id)
);
CREATE INDEX idx_user_roles_role ON user_roles(role_id);
CREATE TABLE role_permissions (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (role_id, permission_id)
);
CREATE INDEX idx_role_permissions_permission ON role_permissions(permission_id);

CREATE TABLE branches (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(30) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, code),
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_branches_organisation ON branches(organisation_id);
CREATE TABLE departments (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    branch_id BIGINT,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, code),
    UNIQUE (organisation_id, id),
    FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_departments_organisation ON departments(organisation_id);
CREATE INDEX idx_departments_branch ON departments(branch_id);

CREATE TABLE audit_logs (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT REFERENCES organisations(id) ON DELETE SET NULL,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    action_type VARCHAR(100) NOT NULL,
    entity_name VARCHAR(100) NOT NULL,
    details JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_logs_org_created ON audit_logs(organisation_id, created_at);
CREATE INDEX idx_audit_logs_user_created ON audit_logs(user_id, created_at);

CREATE TABLE password_reset_tokens (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_password_reset_user_expiry ON password_reset_tokens(user_id, expires_at);
CREATE TABLE login_attempts (
    bucket_hash CHAR(64) PRIMARY KEY,
    failed_attempts SMALLINT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
    window_started_at TIMESTAMPTZ NOT NULL,
    locked_until TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_login_attempts_locked_until ON login_attempts(locked_until);
CREATE INDEX idx_login_attempts_updated ON login_attempts(updated_at);
CREATE TABLE login_history (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT REFERENCES organisations(id) ON DELETE SET NULL,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    email VARCHAR(254) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('success','failed')),
    ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_login_history_user_created ON login_history(user_id, created_at);
CREATE INDEX idx_login_history_org_created ON login_history(organisation_id, created_at);
CREATE INDEX idx_login_history_email_created ON login_history(email, created_at);

CREATE TABLE product_categories (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, name),
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_product_categories_org ON product_categories(organisation_id);
CREATE TABLE products (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    branch_id BIGINT,
    category_id BIGINT,
    name VARCHAR(180) NOT NULL,
    sku VARCHAR(80) NOT NULL,
    unit_cost NUMERIC(18,2) NOT NULL DEFAULT 0,
    selling_price NUMERIC(18,2) NOT NULL DEFAULT 0,
    stock_level INTEGER NOT NULL DEFAULT 0,
    reorder_level INTEGER NOT NULL DEFAULT 0,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (organisation_id, sku),
    UNIQUE (organisation_id, id),
    FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE SET NULL (branch_id),
    FOREIGN KEY (organisation_id, category_id) REFERENCES product_categories(organisation_id, id) ON DELETE SET NULL (category_id)
);
CREATE INDEX idx_products_org_branch ON products(organisation_id, branch_id);
CREATE INDEX idx_products_org_category ON products(organisation_id, category_id);

CREATE TABLE customers (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    name VARCHAR(180) NOT NULL,
    email VARCHAR(254),
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive','blocked')),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_customers_org ON customers(organisation_id);
CREATE TABLE suppliers (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    name VARCHAR(180) NOT NULL,
    contact_email VARCHAR(254),
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_suppliers_org ON suppliers(organisation_id);

CREATE TABLE sales (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    branch_id BIGINT,
    department_id BIGINT,
    customer_id BIGINT,
    sale_date TIMESTAMPTZ NOT NULL,
    subtotal NUMERIC(18,2) NOT NULL DEFAULT 0,
    discount NUMERIC(18,2) NOT NULL DEFAULT 0,
    tax NUMERIC(18,2) NOT NULL DEFAULT 0,
    total NUMERIC(18,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'posted' CHECK (status IN ('draft','posted','refunded')),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (organisation_id, id),
    FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE SET NULL (branch_id),
    FOREIGN KEY (organisation_id, department_id) REFERENCES departments(organisation_id, id) ON DELETE SET NULL (department_id),
    FOREIGN KEY (organisation_id, customer_id) REFERENCES customers(organisation_id, id) ON DELETE SET NULL (customer_id)
);
CREATE INDEX idx_sales_org_date ON sales(organisation_id, sale_date);
CREATE INDEX idx_sales_org_branch ON sales(organisation_id, branch_id);
CREATE INDEX idx_sales_org_customer ON sales(organisation_id, customer_id);

CREATE TABLE sale_items (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL,
    sale_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price NUMERIC(18,2) NOT NULL,
    unit_cost NUMERIC(18,2) NOT NULL DEFAULT 0,
    discount NUMERIC(18,2) NOT NULL DEFAULT 0,
    total NUMERIC(18,2) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    FOREIGN KEY (organisation_id, sale_id) REFERENCES sales(organisation_id, id) ON DELETE CASCADE,
    FOREIGN KEY (organisation_id, product_id) REFERENCES products(organisation_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_sale_items_sale ON sale_items(sale_id);
CREATE INDEX idx_sale_items_product ON sale_items(product_id);
CREATE INDEX idx_sale_items_org_sale ON sale_items(organisation_id, sale_id);

CREATE TABLE expense_categories (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'operating' CHECK (type IN ('operating','administrative','inventory','other')),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, name),
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_expense_category_org ON expense_categories(organisation_id);
CREATE TABLE expenses (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    branch_id BIGINT,
    department_id BIGINT,
    category_id BIGINT,
    expense_date TIMESTAMPTZ NOT NULL,
    amount NUMERIC(18,2) NOT NULL CHECK (amount >= 0),
    description VARCHAR(255) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE SET NULL (branch_id),
    FOREIGN KEY (organisation_id, department_id) REFERENCES departments(organisation_id, id) ON DELETE SET NULL (department_id),
    FOREIGN KEY (organisation_id, category_id) REFERENCES expense_categories(organisation_id, id) ON DELETE SET NULL (category_id)
);
CREATE INDEX idx_expenses_org_date ON expenses(organisation_id, expense_date);
CREATE INDEX idx_expenses_org_branch ON expenses(organisation_id, branch_id);
CREATE INDEX idx_expenses_org_category ON expenses(organisation_id, category_id);

CREATE TABLE invoices (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    customer_id BIGINT NOT NULL,
    branch_id BIGINT,
    invoice_number VARCHAR(120) NOT NULL,
    issue_date TIMESTAMPTZ NOT NULL,
    due_date TIMESTAMPTZ NOT NULL,
    total NUMERIC(18,2) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(18,2) NOT NULL DEFAULT 0,
    paid_amount NUMERIC(18,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'issued' CHECK (status IN ('draft','issued','partial','paid','overdue')),
    sale_id BIGINT,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (organisation_id, invoice_number),
    UNIQUE (organisation_id, id),
    UNIQUE (sale_id),
    FOREIGN KEY (organisation_id, customer_id) REFERENCES customers(organisation_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE SET NULL (branch_id),
    FOREIGN KEY (organisation_id, sale_id) REFERENCES sales(organisation_id, id) ON DELETE SET NULL (sale_id)
);
CREATE INDEX idx_invoices_org_customer ON invoices(organisation_id, customer_id);
CREATE TABLE invoice_items (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL,
    invoice_id BIGINT NOT NULL,
    product_id BIGINT,
    description VARCHAR(255) NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price NUMERIC(18,2) NOT NULL,
    unit_cost NUMERIC(18,2) NOT NULL DEFAULT 0,
    total NUMERIC(18,2) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (organisation_id, id),
    FOREIGN KEY (organisation_id, invoice_id) REFERENCES invoices(organisation_id, id) ON DELETE CASCADE,
    FOREIGN KEY (organisation_id, product_id) REFERENCES products(organisation_id, id) ON DELETE SET NULL (product_id)
);
CREATE INDEX idx_invoice_items_invoice ON invoice_items(invoice_id);
CREATE INDEX idx_invoice_items_product ON invoice_items(product_id);
CREATE INDEX idx_invoice_items_org_invoice ON invoice_items(organisation_id, invoice_id);

CREATE TABLE credit_notes (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    invoice_id BIGINT NOT NULL,
    credit_number VARCHAR(120) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    subtotal NUMERIC(18,2) NOT NULL,
    tax_amount NUMERIC(18,2) NOT NULL DEFAULT 0,
    total NUMERIC(18,2) NOT NULL,
    stock_returned BOOLEAN NOT NULL DEFAULT FALSE,
    created_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (organisation_id, credit_number),
    UNIQUE (organisation_id, id),
    FOREIGN KEY (organisation_id, invoice_id) REFERENCES invoices(organisation_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_credit_notes_org_invoice ON credit_notes(organisation_id, invoice_id, created_at);
CREATE TABLE credit_note_items (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL,
    credit_note_id BIGINT NOT NULL,
    invoice_item_id BIGINT NOT NULL,
    product_id BIGINT,
    quantity INTEGER NOT NULL,
    returned_quantity INTEGER NOT NULL DEFAULT 0,
    unit_price NUMERIC(18,2) NOT NULL,
    unit_cost NUMERIC(18,2) NOT NULL DEFAULT 0,
    subtotal NUMERIC(18,2) NOT NULL,
    tax_amount NUMERIC(18,2) NOT NULL DEFAULT 0,
    total NUMERIC(18,2) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    FOREIGN KEY (organisation_id, credit_note_id) REFERENCES credit_notes(organisation_id, id) ON DELETE CASCADE,
    FOREIGN KEY (organisation_id, invoice_item_id) REFERENCES invoice_items(organisation_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (organisation_id, product_id) REFERENCES products(organisation_id, id) ON DELETE SET NULL (product_id)
);
CREATE INDEX idx_credit_note_items_note ON credit_note_items(credit_note_id);
CREATE INDEX idx_credit_note_items_invoice_item ON credit_note_items(invoice_item_id);
CREATE INDEX idx_credit_note_items_org_note ON credit_note_items(organisation_id, credit_note_id);

CREATE TABLE invoice_refunds (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    invoice_id BIGINT NOT NULL,
    credit_note_id BIGINT NOT NULL,
    amount NUMERIC(18,2) NOT NULL,
    method VARCHAR(60) NOT NULL,
    reference VARCHAR(120),
    refunded_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (credit_note_id),
    FOREIGN KEY (organisation_id, invoice_id) REFERENCES invoices(organisation_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (organisation_id, credit_note_id) REFERENCES credit_notes(organisation_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_invoice_refunds_org_invoice ON invoice_refunds(organisation_id, invoice_id, refunded_at);
CREATE TABLE payments (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    invoice_id BIGINT,
    customer_id BIGINT,
    payment_date TIMESTAMPTZ NOT NULL,
    amount NUMERIC(18,2) NOT NULL,
    method VARCHAR(60) NOT NULL,
    reference VARCHAR(120),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    FOREIGN KEY (organisation_id, invoice_id) REFERENCES invoices(organisation_id, id) ON DELETE SET NULL (invoice_id),
    FOREIGN KEY (organisation_id, customer_id) REFERENCES customers(organisation_id, id) ON DELETE SET NULL (customer_id)
);
CREATE INDEX idx_payments_org_date ON payments(organisation_id, payment_date);
CREATE INDEX idx_payments_invoice ON payments(invoice_id);
CREATE INDEX idx_payments_customer ON payments(customer_id);

CREATE TABLE stock_movements (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL,
    branch_id BIGINT,
    movement_type VARCHAR(20) NOT NULL CHECK (movement_type IN ('receipt','adjustment','transfer','sale','return')),
    quantity INTEGER NOT NULL,
    unit_cost NUMERIC(18,2) NOT NULL DEFAULT 0,
    reference_type VARCHAR(80),
    reference_id BIGINT,
    notes VARCHAR(255),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    FOREIGN KEY (organisation_id, product_id) REFERENCES products(organisation_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE SET NULL (branch_id)
);
CREATE INDEX idx_stock_movements_org_product ON stock_movements(organisation_id, product_id, created_at);
CREATE INDEX idx_stock_movements_branch ON stock_movements(branch_id);

ALTER TABLE invoices ADD CONSTRAINT fk_invoices_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL;

CREATE TABLE purchase_orders (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    supplier_id BIGINT NOT NULL,
    branch_id BIGINT,
    order_date TIMESTAMPTZ NOT NULL,
    expected_date TIMESTAMPTZ,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','received','closed')),
    total NUMERIC(18,2) NOT NULL DEFAULT 0,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    UNIQUE (organisation_id, id),
    FOREIGN KEY (organisation_id, supplier_id) REFERENCES suppliers(organisation_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE SET NULL (branch_id)
);
CREATE INDEX idx_purchase_orders_org_date ON purchase_orders(organisation_id, order_date);
CREATE TABLE purchase_order_items (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL,
    purchase_order_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    quantity INTEGER NOT NULL,
    unit_cost NUMERIC(18,2) NOT NULL,
    total NUMERIC(18,2) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    FOREIGN KEY (organisation_id, purchase_order_id) REFERENCES purchase_orders(organisation_id, id) ON DELETE CASCADE,
    FOREIGN KEY (organisation_id, product_id) REFERENCES products(organisation_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_po_items_order ON purchase_order_items(purchase_order_id);
CREATE INDEX idx_po_items_product ON purchase_order_items(product_id);
CREATE INDEX idx_purchase_order_items_org_order ON purchase_order_items(organisation_id, purchase_order_id);

CREATE TABLE intelligence_insights (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    insight_type VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    summary TEXT NOT NULL,
    score NUMERIC(5,2),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_intelligence_insights_org ON intelligence_insights(organisation_id, created_at);
CREATE TABLE risk_alerts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    category VARCHAR(120) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'medium' CHECK (severity IN ('low','medium','high','critical')),
    detected_at TIMESTAMPTZ NOT NULL,
    business_area VARCHAR(120) NOT NULL,
    evidence JSONB,
    explanation TEXT NOT NULL,
    suggested_action TEXT NOT NULL,
    reviewer_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK (status IN ('open','assigned','reviewing','resolved','dismissed')),
    resolution_notes TEXT,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_risk_alerts_org_status ON risk_alerts(organisation_id, status, detected_at);

CREATE TABLE forecasts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    forecast_type VARCHAR(120) NOT NULL,
    forecast_for TIMESTAMPTZ NOT NULL,
    methodology VARCHAR(180) NOT NULL,
    model_version VARCHAR(80) NOT NULL,
    data_coverage NUMERIC(5,2) NOT NULL DEFAULT 0,
    confidence_interval NUMERIC(5,2),
    assumptions JSONB,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    generated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_forecasts_org_date ON forecasts(organisation_id, forecast_for);
CREATE TABLE forecast_results (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL,
    forecast_id BIGINT NOT NULL,
    period_label VARCHAR(80) NOT NULL,
    actual_value NUMERIC(18,2),
    projected_value NUMERIC(18,2) NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (organisation_id, forecast_id) REFERENCES forecasts(organisation_id, id) ON DELETE CASCADE
);
CREATE INDEX idx_forecast_results_forecast ON forecast_results(forecast_id);
CREATE INDEX idx_forecast_results_org_forecast ON forecast_results(organisation_id, forecast_id);

CREATE TABLE model_versions (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    model_name VARCHAR(120) NOT NULL,
    version VARCHAR(60) NOT NULL,
    summary TEXT NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_model_versions_org ON model_versions(organisation_id);
CREATE TABLE decision_scenarios (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    name VARCHAR(180) NOT NULL,
    description TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','archived')),
    created_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_decision_scenarios_org ON decision_scenarios(organisation_id, created_at);
CREATE TABLE scenario_assumptions (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL,
    scenario_id BIGINT NOT NULL,
    assumption_key VARCHAR(120) NOT NULL,
    assumption_value JSONB NOT NULL,
    comment TEXT,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (organisation_id, scenario_id) REFERENCES decision_scenarios(organisation_id, id) ON DELETE CASCADE
);
CREATE INDEX idx_scenario_assumptions_scenario ON scenario_assumptions(scenario_id);
CREATE INDEX idx_scenario_assumptions_org_scenario ON scenario_assumptions(organisation_id, scenario_id);
CREATE TABLE scenario_results (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL,
    scenario_id BIGINT NOT NULL,
    label VARCHAR(120) NOT NULL,
    metric_name VARCHAR(120) NOT NULL,
    actual_value NUMERIC(18,2),
    projected_value NUMERIC(18,2) NOT NULL,
    variance NUMERIC(18,2),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (organisation_id, scenario_id) REFERENCES decision_scenarios(organisation_id, id) ON DELETE CASCADE
);
CREATE INDEX idx_scenario_results_scenario ON scenario_results(scenario_id);
CREATE INDEX idx_scenario_results_org_scenario ON scenario_results(organisation_id, scenario_id);

CREATE TABLE imported_files (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL CHECK (file_size >= 0),
    mime_type VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'uploaded' CHECK (status IN ('uploaded','validated','imported','failed')),
    uploaded_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    uploaded_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_imported_files_org_time ON imported_files(organisation_id, uploaded_at);
CREATE TABLE import_errors (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL,
    import_id BIGINT NOT NULL,
    row_number INTEGER NOT NULL,
    error_message TEXT NOT NULL,
    raw_data JSONB,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (organisation_id, import_id) REFERENCES imported_files(organisation_id, id) ON DELETE CASCADE
);
CREATE INDEX idx_import_errors_import ON import_errors(import_id);
CREATE INDEX idx_import_errors_org_import ON import_errors(organisation_id, import_id);
CREATE TABLE notifications (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    type VARCHAR(60) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_notifications_org_user ON notifications(organisation_id, user_id, created_at);

CREATE TABLE employees (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    employee_number VARCHAR(80) NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    email VARCHAR(254),
    department_id BIGINT,
    branch_id BIGINT,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive','terminated')),
    source VARCHAR(20) NOT NULL DEFAULT 'manual' CHECK (source IN ('manual','upload','integration')),
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed' CHECK (data_quality_status IN ('unreviewed','validated','rejected')),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, employee_number),
    UNIQUE (organisation_id, id),
    FOREIGN KEY (organisation_id, department_id) REFERENCES departments(organisation_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (organisation_id, branch_id) REFERENCES branches(organisation_id, id) ON DELETE RESTRICT
);
CREATE TABLE ledger_accounts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    account_code VARCHAR(80) NOT NULL,
    name VARCHAR(180) NOT NULL,
    account_type VARCHAR(20) NOT NULL CHECK (account_type IN ('asset','liability','equity','revenue','expense')),
    currency_code CHAR(3) NOT NULL DEFAULT 'GHS',
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    source VARCHAR(20) NOT NULL DEFAULT 'manual' CHECK (source IN ('manual','upload','integration')),
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, account_code),
    UNIQUE (organisation_id, id)
);
CREATE INDEX idx_ledger_accounts_org_type ON ledger_accounts(organisation_id, account_type, status);
CREATE TABLE ledger_entries (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    ledger_account_id BIGINT NOT NULL,
    entry_date DATE NOT NULL,
    journal_reference VARCHAR(120),
    description VARCHAR(255) NOT NULL,
    debit NUMERIC(18,2) NOT NULL DEFAULT 0 CHECK (debit >= 0),
    credit NUMERIC(18,2) NOT NULL DEFAULT 0 CHECK (credit >= 0),
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255),
    import_batch_id BIGINT,
    data_quality_status VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (organisation_id, ledger_account_id) REFERENCES ledger_accounts(organisation_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_ledger_entries_org_date ON ledger_entries(organisation_id, entry_date);
CREATE INDEX idx_ledger_entries_org_account_date ON ledger_entries(organisation_id, ledger_account_id, entry_date);

CREATE TABLE data_import_batches (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    uploaded_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    file_name VARCHAR(255) NOT NULL,
    data_type VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'uploaded' CHECK (status IN ('uploaded','validated','committed','rolled_back','failed')),
    column_mapping JSONB,
    row_count INTEGER NOT NULL DEFAULT 0 CHECK (row_count >= 0),
    error_count INTEGER NOT NULL DEFAULT 0 CHECK (error_count >= 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    committed_at TIMESTAMPTZ
);
CREATE INDEX idx_data_imports_org_created ON data_import_batches(organisation_id, created_at);
CREATE TABLE data_import_rows (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    batch_id BIGINT NOT NULL REFERENCES data_import_batches(id) ON DELETE CASCADE,
    row_number INTEGER NOT NULL CHECK (row_number >= 0),
    source_data JSONB NOT NULL,
    normalized_data JSONB,
    error_message VARCHAR(500),
    UNIQUE (batch_id, row_number)
);

CREATE TABLE documents (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    version_group CHAR(36) NOT NULL,
    version_number INTEGER NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(64) NOT NULL,
    content_sha256 CHAR(64),
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT NOT NULL CHECK (file_size >= 0),
    category VARCHAR(40) NOT NULL,
    extracted_text TEXT,
    extraction_status VARCHAR(20) NOT NULL DEFAULT 'unsupported' CHECK (extraction_status IN ('available','unsupported','failed')),
    reviewed_at TIMESTAMPTZ,
    uploaded_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organisation_id, version_group, version_number),
    UNIQUE (organisation_id, content_sha256)
);
CREATE INDEX idx_documents_org_created ON documents(organisation_id, created_at);
CREATE INDEX idx_documents_org_group ON documents(organisation_id, version_group);
CREATE TABLE integrations (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL,
    connector_type VARCHAR(80) NOT NULL,
    endpoint_url VARCHAR(2048),
    encrypted_credentials TEXT,
    field_mappings JSONB,
    sync_schedule VARCHAR(40) NOT NULL DEFAULT 'manual',
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','error','paused')),
    last_synced_at TIMESTAMPTZ,
    records_synced BIGINT NOT NULL DEFAULT 0,
    last_error VARCHAR(500),
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_integrations_org_status ON integrations(organisation_id, status);
CREATE TABLE integration_sync_runs (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    integration_id BIGINT NOT NULL REFERENCES integrations(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL CHECK (status IN ('success','error')),
    records_synced INTEGER NOT NULL DEFAULT 0,
    error_message VARCHAR(500),
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at TIMESTAMPTZ
);
CREATE INDEX idx_integration_sync_runs ON integration_sync_runs(integration_id, started_at);

CREATE TABLE outbox_events (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id CHAR(36) NOT NULL UNIQUE,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    aggregate_type VARCHAR(64) NOT NULL,
    aggregate_id VARCHAR(128) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    event_version SMALLINT NOT NULL DEFAULT 1,
    event_payload JSONB NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','processing','published','failed')),
    attempts SMALLINT NOT NULL DEFAULT 0,
    available_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    locked_at TIMESTAMPTZ,
    lock_token CHAR(36),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_at TIMESTAMPTZ,
    last_error VARCHAR(160)
);
CREATE INDEX idx_outbox_dispatch ON outbox_events(status, available_at, id);
CREATE INDEX idx_outbox_org_sequence ON outbox_events(organisation_id, id);
CREATE TABLE event_notifications (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    outbox_id BIGINT NOT NULL UNIQUE REFERENCES outbox_events(id) ON DELETE CASCADE,
    event_id CHAR(36) NOT NULL UNIQUE,
    organisation_id BIGINT NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    event_type VARCHAR(100) NOT NULL,
    summary VARCHAR(200) NOT NULL,
    modules JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_event_notifications_org_sequence ON event_notifications(organisation_id, id);
