ALTER TABLE documents ADD COLUMN content_sha256 CHAR(64) NULL AFTER stored_name;
ALTER TABLE documents ADD UNIQUE KEY uq_documents_org_content_sha256 (organisation_id, content_sha256);
