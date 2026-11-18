<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;
use Vantiq\Http\ApiException;

final class DataWorkspaceRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?AuditRepository $audit = null,
        private readonly ?OutboxRepository $outbox = null
    ) {
    }

    public function documents(int $organisationId, string $search = '', string $category = ''): array
    {
        $sql = 'SELECT id, version_group, version_number, original_name, mime_type, file_size, category,
                       extraction_status, reviewed_at, created_at
                FROM documents WHERE organisation_id = :organisation_id';
        $params = ['organisation_id' => $organisationId];
        if ($search !== '') {
            $sql .= ' AND original_name LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        if ($category !== '') {
            $sql .= ' AND category = :category';
            $params['category'] = $category;
        }
        $sql .= ' ORDER BY created_at DESC, version_number DESC LIMIT 100';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function documentVersions(int $organisationId, string $group): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, version_number, original_name, file_size, extraction_status, reviewed_at, created_at
             FROM documents WHERE organisation_id = :organisation_id AND version_group = :version_group
             ORDER BY version_number DESC'
        );
        $statement->execute(['organisation_id' => $organisationId, 'version_group' => $group]);
        return $statement->fetchAll();
    }

    public function documentExtraction(int $organisationId, int $documentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, original_name, extracted_text, extraction_status, reviewed_at
             FROM documents WHERE id = :id AND organisation_id = :organisation_id'
        );
        $statement->execute(['id' => $documentId, 'organisation_id' => $organisationId]);
        $document = $statement->fetch();
        if (!is_array($document)) {
            throw new ApiException(404, 'document_not_found', 'The document was not found.');
        }
        return $document;
    }

    public function latestDocumentVersion(int $organisationId, string $name): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT version_group, version_number FROM documents
             WHERE organisation_id = :organisation_id AND original_name = :original_name
             ORDER BY version_number DESC LIMIT 1'
        );
        $statement->execute(['organisation_id' => $organisationId, 'original_name' => $name]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function documentByHash(int $organisationId, string $contentHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, version_group, version_number, original_name, extraction_status
             FROM documents WHERE organisation_id = :organisation_id AND content_sha256 = :content_sha256
             LIMIT 1'
        );
        $statement->execute(['organisation_id' => $organisationId, 'content_sha256' => $contentHash]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function createDocument(array $document): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO documents
                 (organisation_id, version_group, version_number, original_name, stored_name, content_sha256, mime_type,
                  file_size, category, extracted_text, extraction_status, uploaded_by)
                 VALUES
                 (:organisation_id, :version_group, :version_number, :original_name, :stored_name, :content_sha256, :mime_type,
                  :file_size, :category, :extracted_text, :extraction_status, :uploaded_by)'
            );
            $statement->execute($document);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit?->record(
                (int) $document['organisation_id'],
                (int) $document['uploaded_by'],
                'document.uploaded',
                'document',
                ['document_id' => $id, 'version' => (int) $document['version_number']]
            );
            $this->outbox?->enqueue(
                (int) $document['organisation_id'],
                'document',
                $id,
                'DocumentUploaded',
                ['document_id' => $id, 'version' => (int) $document['version_number']]
            );
            if ($document['extraction_status'] === 'available') {
                $this->outbox?->enqueue(
                    (int) $document['organisation_id'],
                    'document',
                    $id,
                    'DocumentAnalysisCompleted',
                    ['document_id' => $id, 'version' => (int) $document['version_number']]
                );
            }
            $this->pdo->commit();
            return ['id' => $id, 'version' => $document['version_number'], 'duplicate' => false];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof \PDOException && isset($document['content_sha256'])) {
                $existing = $this->documentByHash(
                    (int) $document['organisation_id'],
                    (string) $document['content_sha256']
                );
                if ($existing !== null) {
                    return [
                        'id' => (int) $existing['id'],
                        'version' => (int) $existing['version_number'],
                        'duplicate' => true,
                    ];
                }
            }
            throw $exception;
        }
    }

    public function markDocumentReviewed(int $organisationId, int $userId, int $documentId): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE documents SET reviewed_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND organisation_id = :organisation_id'
            );
            $statement->execute(['id' => $documentId, 'organisation_id' => $organisationId]);
            if ($statement->rowCount() !== 1) {
                $exists = $this->pdo->prepare(
                    'SELECT 1 FROM documents WHERE id = :id AND organisation_id = :organisation_id'
                );
                $exists->execute(['id' => $documentId, 'organisation_id' => $organisationId]);
                if ($exists->fetchColumn() === false) {
                    throw new ApiException(404, 'document_not_found', 'The document was not found.');
                }
            }
            $this->audit?->record($organisationId, $userId, 'document.reviewed', 'document', ['document_id' => $documentId]);
            $this->outbox?->enqueue($organisationId, 'document', $documentId, 'DocumentReviewed', ['document_id' => $documentId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createImportBatch(
        int $organisationId,
        int $userId,
        string $fileName,
        string $dataType,
        array $rows
    ): int {
        $this->pdo->beginTransaction();
        try {
            $batch = $this->pdo->prepare(
                'INSERT INTO data_import_batches (organisation_id, uploaded_by, file_name, data_type, row_count)
                 VALUES (:organisation_id, :uploaded_by, :file_name, :data_type, :row_count)'
            );
            $batch->execute([
                'organisation_id' => $organisationId,
                'uploaded_by' => $userId,
                'file_name' => $fileName,
                'data_type' => $dataType,
                'row_count' => count($rows),
            ]);
            $batchId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO data_import_rows (batch_id, row_number, source_data)
                 VALUES (:batch_id, :row_number, :source_data)'
            );
            foreach ($rows as $offset => $row) {
                $insert->execute([
                    'batch_id' => $batchId,
                    'row_number' => $offset + 2,
                    'source_data' => json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
            }
            $this->pdo->commit();
            return $batchId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function importBatch(int $organisationId, int $batchId, bool $forUpdate = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, uploaded_by, data_type, status, column_mapping
             FROM data_import_batches WHERE id = :id AND organisation_id = :organisation_id'
             . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $batchId, 'organisation_id' => $organisationId]);
        $batch = $statement->fetch();
        if (!is_array($batch)) {
            throw new ApiException(404, 'import_not_found', 'The import batch was not found.');
        }
        return $batch;
    }

    public function validateImport(
        int $organisationId,
        int $batchId,
        array $mapping,
        array $normalizedRows,
        array $errors
    ): void {
        $this->pdo->beginTransaction();
        try {
            $check = $this->importBatch($organisationId, $batchId, true);
            if (!in_array($check['status'], ['uploaded', 'validated', 'failed'], true)) {
                throw new ApiException(409, 'import_not_editable', 'This import can no longer be changed.');
            }
            $update = $this->pdo->prepare(
                'UPDATE data_import_rows SET normalized_data = :normalized_data, error_message = :error_message
                 WHERE batch_id = :batch_id AND row_number = :row_number'
            );
            foreach ($normalizedRows as $rowNumber => $data) {
                $update->execute([
                    'normalized_data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    'error_message' => $errors[$rowNumber] ?? null,
                    'batch_id' => $batchId,
                    'row_number' => $rowNumber,
                ]);
            }
            $failedCount = count($errors);
            $status = $failedCount === 0 ? 'validated' : 'failed';
            $save = $this->pdo->prepare(
                'UPDATE data_import_batches
                 SET column_mapping = :mapping, status = :status, error_count = :error_count
                 WHERE id = :id AND organisation_id = :organisation_id'
            );
            $save->execute([
                'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
                'status' => $status,
                'error_count' => $failedCount,
                'id' => $batchId,
                'organisation_id' => $organisationId,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function importRows(int $batchId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT row_number, normalized_data, error_message FROM data_import_rows
             WHERE batch_id = :batch_id ORDER BY row_number'
        );
        $statement->execute(['batch_id' => $batchId]);
        return $statement->fetchAll();
    }

    public function finishImport(int $organisationId, int $batchId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE data_import_batches SET status = :status,
             committed_at = CASE WHEN :committed_status = "committed" THEN CURRENT_TIMESTAMP ELSE committed_at END
             WHERE id = :id AND organisation_id = :organisation_id'
        );
        $statement->execute([
            'status' => $status,
            'committed_status' => $status,
            'id' => $batchId,
            'organisation_id' => $organisationId,
        ]);
    }

    public function history(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, file_name, data_type, status, row_count, error_count, created_at, committed_at
             FROM data_import_batches WHERE organisation_id = :organisation_id
             ORDER BY created_at DESC LIMIT 100'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return $statement->fetchAll();
    }

    public function rollbackImportedRows(int $organisationId, int $batchId, string $dataType): void
    {
        $tables = [
            'sales' => 'sales',
            'expenses' => 'expenses',
            'customers' => 'customers',
            'suppliers' => 'suppliers',
            'products' => 'products',
        ];
        $table = $tables[$dataType] ?? null;
        if ($table === null) {
            throw new ApiException(422, 'unsupported_data_type', 'This import type cannot be rolled back.');
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $table . ' WHERE organisation_id = :organisation_id AND import_batch_id = :batch_id'
        );
        $statement->execute(['organisation_id' => $organisationId, 'batch_id' => $batchId]);
    }

    public function integrations(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, category, connector_type, endpoint_url, field_mappings, sync_schedule,
                    status, last_synced_at, records_synced, last_error, created_at
             FROM integrations WHERE organisation_id = :organisation_id ORDER BY created_at DESC'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['field_mappings'] = $row['field_mappings'] === null ? [] : json_decode($row['field_mappings'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return $rows;
    }

    public function dueIntegrations(): array
    {
        return $this->pdo->query(
            'SELECT id, organisation_id FROM integrations
             WHERE status = "active" AND sync_schedule <> "manual"
               AND (
                 last_synced_at IS NULL
                 OR (sync_schedule = "hourly" AND last_synced_at <= CURRENT_TIMESTAMP - INTERVAL 1 HOUR)
                 OR (sync_schedule = "daily" AND last_synced_at <= CURRENT_TIMESTAMP - INTERVAL 1 DAY)
                 OR (sync_schedule = "weekly" AND last_synced_at <= CURRENT_TIMESTAMP - INTERVAL 1 WEEK)
               )
             ORDER BY last_synced_at ASC'
        )->fetchAll();
    }

    public function createIntegration(int $organisationId, int $userId, array $integration): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO integrations
             (organisation_id, created_by, name, category, connector_type, endpoint_url,
              encrypted_credentials, sync_schedule, field_mappings, status)
             VALUES
             (:organisation_id, :created_by, :name, :category, :connector_type, :endpoint_url,
              :encrypted_credentials, :sync_schedule, :field_mappings, "draft")'
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'created_by' => $userId,
            'name' => $integration['name'],
            'category' => $integration['category'],
            'connector_type' => $integration['connector_type'],
            'endpoint_url' => $integration['endpoint_url'],
            'encrypted_credentials' => $integration['encrypted_credentials'],
            'sync_schedule' => $integration['sync_schedule'],
            'field_mappings' => json_encode($integration['field_mappings'], JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function integration(int $organisationId, int $integrationId, bool $includeCredentials = false): array
    {
        $columns = $includeCredentials
            ? 'id, connector_type, endpoint_url, encrypted_credentials, status, records_synced'
            : 'id, name, category, connector_type, endpoint_url, field_mappings, sync_schedule, status, last_synced_at, records_synced, last_error';
        $statement = $this->pdo->prepare(
            'SELECT ' . $columns . ' FROM integrations
             WHERE id = :id AND organisation_id = :organisation_id'
        );
        $statement->execute(['id' => $integrationId, 'organisation_id' => $organisationId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'integration_not_found', 'The integration was not found.');
        }
        return $row;
    }

    public function updateIntegration(int $organisationId, int $integrationId, array $values): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE integrations SET endpoint_url = :endpoint_url, encrypted_credentials = :credentials,
             field_mappings = :field_mappings, sync_schedule = :sync_schedule, status = :status,
             last_synced_at = CURRENT_TIMESTAMP, records_synced = records_synced + :records_synced,
             last_error = :last_error
             WHERE id = :id AND organisation_id = :organisation_id'
        );
        $statement->execute([
            'endpoint_url' => $values['endpoint_url'],
            'credentials' => $values['encrypted_credentials'],
            'field_mappings' => json_encode($values['field_mappings'], JSON_THROW_ON_ERROR),
            'sync_schedule' => $values['sync_schedule'],
            'status' => $values['status'],
            'records_synced' => $values['records_synced'],
            'last_error' => $values['last_error'],
            'id' => $integrationId,
            'organisation_id' => $organisationId,
        ]);
    }

    public function syncRun(int $integrationId, string $status, int $records, ?string $error): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO integration_sync_runs (integration_id, status, records_synced, error_message, completed_at)
             VALUES (:integration_id, :status, :records, :error, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'integration_id' => $integrationId,
            'status' => $status,
            'records' => $records,
            'error' => $error,
        ]);
    }
}
