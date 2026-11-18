CREATE TABLE IF NOT EXISTS data_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED DEFAULT NULL,
    file_name VARCHAR(255) NOT NULL,
    data_type VARCHAR(32) NOT NULL,
    status ENUM('uploaded', 'validated', 'committed', 'rolled_back', 'failed') NOT NULL DEFAULT 'uploaded',
    column_mapping JSON DEFAULT NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    committed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_data_imports_org_created (organisation_id, created_at),
    CONSTRAINT fk_data_imports_org FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
    CONSTRAINT fk_data_imports_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    source_data JSON NOT NULL,
    normalized_data JSON DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_data_import_rows_batch_row (batch_id, row_number),
    CONSTRAINT fk_data_import_rows_batch FOREIGN KEY (batch_id) REFERENCES data_import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    version_group CHAR(36) NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(64) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL,
    extracted_text LONGTEXT DEFAULT NULL,
    extraction_status ENUM('available', 'unsupported', 'failed') NOT NULL DEFAULT 'unsupported',
    reviewed_at DATETIME DEFAULT NULL,
    uploaded_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_version (organisation_id, version_group, version_number),
    KEY idx_documents_org_created (organisation_id, created_at),
    KEY idx_documents_org_group (organisation_id, version_group),
    CONSTRAINT fk_documents_org FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL,
    connector_type VARCHAR(80) NOT NULL,
    endpoint_url VARCHAR(2048) DEFAULT NULL,
    encrypted_credentials TEXT DEFAULT NULL,
    field_mappings JSON DEFAULT NULL,
    sync_schedule VARCHAR(40) NOT NULL DEFAULT 'manual',
    status ENUM('draft', 'active', 'error', 'paused') NOT NULL DEFAULT 'draft',
    last_synced_at DATETIME DEFAULT NULL,
    records_synced BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_integrations_org_status (organisation_id, status),
    CONSTRAINT fk_integrations_org FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
    CONSTRAINT fk_integrations_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_sync_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration_id BIGINT UNSIGNED NOT NULL,
    status ENUM('success', 'error') NOT NULL,
    records_synced INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_integration_sync_runs (integration_id, started_at),
    CONSTRAINT fk_sync_runs_integration FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description)
VALUES
    ('documents.read', 'View organisation documents'),
    ('documents.upload', 'Upload and review organisation documents'),
    ('data.import', 'Import organisation business data'),
    ('integrations.manage', 'Manage organisation system integrations')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO roles (organisation_id, name, description, is_system, created_at)
SELECT organisations.id, role_names.name, CONCAT(role_names.name, ' role'), 0, CURRENT_TIMESTAMP
FROM organisations
CROSS JOIN (
    SELECT 'Finance Manager' AS name
    UNION ALL SELECT 'Business Analyst'
) AS role_names
WHERE NOT EXISTS (
    SELECT 1 FROM roles
    WHERE roles.organisation_id = organisations.id AND roles.name = role_names.name AND roles.is_system = 0
);

INSERT INTO role_permissions (role_id, permission_id, created_at)
SELECT roles.id, permissions.id, CURRENT_TIMESTAMP
FROM roles
JOIN permissions ON (
    (roles.name = 'Organisation Owner' AND permissions.code IN ('documents.read', 'documents.upload', 'data.import', 'integrations.manage'))
    OR (roles.name = 'Financial Analyst' AND permissions.code IN ('documents.read', 'documents.upload', 'data.import'))
    OR (roles.name = 'Finance Manager' AND permissions.code IN ('finance.read', 'finance.write', 'reports.read', 'documents.read', 'documents.upload', 'data.import'))
    OR (roles.name = 'Business Analyst' AND permissions.code IN ('reports.read', 'documents.read', 'documents.upload', 'data.import'))
)
WHERE roles.organisation_id IS NOT NULL AND roles.is_system = 0
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.id AND existing.permission_id = permissions.id
  );
