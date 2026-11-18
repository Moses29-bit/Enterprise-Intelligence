<?php
declare(strict_types=1);

namespace Vantiq\Service;

use finfo;
use PDO;
use Throwable;
use Vantiq\Config\Config;
use Vantiq\Http\ApiException;
use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\DataWorkspaceRepository;
use Vantiq\Repository\OutboxRepository;

final class DataWorkspaceService
{
    private const MAX_UPLOAD_BYTES = 15728640;
    private const DOCUMENT_CATEGORIES = [
        'financial_statement', 'contract', 'audit_report', 'policy',
        'invoice_receipt', 'other',
    ];
    private const IMPORT_FIELDS = [
        'sales' => ['sale_date', 'amount', 'customer_id'],
        'expenses' => ['expense_date', 'amount', 'description'],
        'customers' => ['name', 'email'],
        'suppliers' => ['name', 'contact_email'],
        'products' => ['name', 'sku', 'unit_cost', 'selling_price', 'stock_level'],
    ];
    private const IMPORT_REQUIRED = [
        'sales' => ['sale_date', 'amount'],
        'expenses' => ['expense_date', 'amount', 'description'],
        'customers' => ['name'],
        'suppliers' => ['name'],
        'products' => ['name', 'sku'],
    ];
    private const TARGET_TABLES = [
        'sales' => 'sales',
        'expenses' => 'expenses',
        'customers' => 'customers',
        'suppliers' => 'suppliers',
        'products' => 'products',
    ];

    public function __construct(
        private readonly DataWorkspaceRepository $repository,
        private readonly Config $config,
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly ?OutboxRepository $outbox = null
    ) {
    }

    public function documents(int $organisationId, array $query): array
    {
        $search = trim((string) ($query['search'] ?? ''));
        $category = trim((string) ($query['category'] ?? ''));
        if ($category !== '' && !in_array($category, self::DOCUMENT_CATEGORIES, true)) {
            throw new ApiException(422, 'validation_failed', 'Choose a supported document category.');
        }
        return [
            'documents' => $this->repository->documents($organisationId, mb_substr($search, 0, 100), $category),
            'categories' => self::DOCUMENT_CATEGORIES,
        ];
    }

    public function documentVersions(int $organisationId, string $group): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $group)) {
            throw new ApiException(422, 'validation_failed', 'The document version group is invalid.');
        }
        return ['versions' => $this->repository->documentVersions($organisationId, $group)];
    }

    public function documentExtraction(int $organisationId, int $documentId): array
    {
        return ['extraction' => $this->repository->documentExtraction($organisationId, $documentId)];
    }

    public function uploadDocument(int $organisationId, int $userId, array $file, array $fields): array
    {
        $category = (string) ($fields['category'] ?? 'other');
        if (!in_array($category, self::DOCUMENT_CATEGORIES, true)) {
            throw new ApiException(422, 'validation_failed', 'Choose a supported document category.');
        }
        [$originalName, $extension, $mime] = $this->validateUpload($file, ['docx', 'pdf', 'xlsx', 'csv', 'txt']);
        $privateDirectory = $this->privateDirectory('documents');
        $storedName = bin2hex(random_bytes(32)) . '.' . $extension;
        $destination = $privateDirectory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            throw new ApiException(500, 'upload_failed', 'The document could not be stored securely.');
        }
        if (!chmod($destination, 0600)) {
            unlink($destination);
            throw new ApiException(500, 'storage_permissions_failed', 'Private file permissions could not be applied.');
        }

        try {
            $contentHash = hash_file('sha256', $destination);
            if (!is_string($contentHash)) {
                throw new ApiException(500, 'document_fingerprint_failed', 'The document could not be verified after upload.');
            }
            $existing = $this->repository->documentByHash($organisationId, $contentHash);
            if ($existing !== null) {
                if (!unlink($destination)) {
                    $referenceId = $this->logger->correlatedError('Duplicate document cleanup failed.', [
                        'operation' => 'POST /documents/upload',
                        'stage' => 'duplicate_cleanup',
                        'user_id' => $userId,
                        'organisation_id' => $organisationId,
                    ]);
                    throw new ApiException(
                        500,
                        'duplicate_cleanup_failed',
                        'The duplicate upload was detected but could not be removed from temporary storage. Contact support.',
                        ['reference_id' => $referenceId]
                    );
                }
                return [
                    'document' => [
                        'id' => (int) $existing['id'],
                        'version' => (int) $existing['version_number'],
                    ],
                    'duplicate' => true,
                    'extraction_status' => $existing['extraction_status'],
                    'text' => '',
                ];
            }

            $latest = $this->repository->latestDocumentVersion($organisationId, $originalName);
            $versionGroup = is_array($latest) ? (string) $latest['version_group'] : $this->uuid();
            $versionNumber = is_array($latest) ? ((int) $latest['version_number'] + 1) : 1;
            [$text, $extractionStatus] = $this->extractText($destination, $extension, [
                'operation' => 'POST /documents/upload',
                'stage' => 'extraction',
                'user_id' => $userId,
                'organisation_id' => $organisationId,
            ]);
            $document = $this->repository->createDocument([
                'organisation_id' => $organisationId,
                'version_group' => $versionGroup,
                'version_number' => $versionNumber,
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'content_sha256' => $contentHash,
                'mime_type' => $mime,
                'file_size' => (int) $file['size'],
                'category' => $category,
                'extracted_text' => $text,
                'extraction_status' => $extractionStatus,
                'uploaded_by' => $userId,
            ]);
            if ($document['duplicate']) {
                if (is_file($destination) && !unlink($destination)) {
                    throw new ApiException(500, 'duplicate_cleanup_failed', 'The duplicate upload could not be removed from temporary storage.');
                }
                return [
                    'document' => ['id' => $document['id'], 'version' => $document['version']],
                    'duplicate' => true,
                    'extraction_status' => $extractionStatus,
                    'text' => '',
                ];
            }
        } catch (Throwable $exception) {
            if (is_file($destination) && !unlink($destination)) {
                $this->logger->correlatedError('Unable to remove a document after its metadata could not be saved.', [
                    'operation' => 'POST /documents/upload',
                    'stage' => 'storage_cleanup',
                    'user_id' => $userId,
                    'organisation_id' => $organisationId,
                ]);
            }
            throw $exception;
        }
        return ['document' => $document, 'duplicate' => false, 'extraction_status' => $extractionStatus, 'text' => $text];
    }

    public function reviewDocument(int $organisationId, int $userId, int $documentId): array
    {
        $this->repository->markDocumentReviewed($organisationId, $userId, $documentId);
        return ['reviewed' => true];
    }

    public function uploadImport(int $organisationId, int $userId, array $file, array $fields): array
    {
        $dataType = (string) ($fields['data_type'] ?? '');
        if (!array_key_exists($dataType, self::IMPORT_FIELDS)) {
            throw new ApiException(422, 'validation_failed', 'Choose a supported financial data type.');
        }
        [$fileName, $extension] = $this->validateUpload($file, ['csv', 'xlsx']);
        $rows = $this->readTabularFile((string) $file['tmp_name'], $extension);
        if (count($rows) < 2) {
            throw new ApiException(422, 'empty_import', 'The file needs a header row and at least one data row.');
        }
        $headers = array_map(static fn (mixed $header): string => trim((string) $header), array_shift($rows));
        if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
            throw new ApiException(422, 'invalid_headers', 'Column headings must be non-empty and unique.');
        }
        $normalizedRows = [];
        foreach (array_slice($rows, 0, 5000) as $row) {
            $values = [];
            foreach ($headers as $index => $header) {
                $values[$header] = trim((string) ($row[$index] ?? ''));
            }
            if (count(array_filter($values, static fn (string $value): bool => $value !== '')) > 0) {
                $normalizedRows[] = $values;
            }
        }
        if ($normalizedRows === []) {
            throw new ApiException(422, 'empty_import', 'No non-empty data rows were found.');
        }
        $batchId = $this->repository->createImportBatch($organisationId, $userId, $fileName, $dataType, $normalizedRows);
        return [
            'batch_id' => $batchId,
            'file_name' => $fileName,
            'data_type' => $dataType,
            'headers' => $headers,
            'fields' => self::IMPORT_FIELDS[$dataType],
            'rows' => array_slice($normalizedRows, 0, 10),
            'row_count' => count($normalizedRows),
        ];
    }

    public function validateImport(int $organisationId, int $batchId, array $payload): array
    {
        $batch = $this->repository->importBatch($organisationId, $batchId);
        $mapping = $payload['mapping'] ?? null;
        if (!is_array($mapping) || array_is_list($mapping)) {
            throw new ApiException(422, 'validation_failed', 'Map each required field to a source column.');
        }
        $availableFields = self::IMPORT_FIELDS[$batch['data_type']] ?? [];
        $requiredFields = self::IMPORT_REQUIRED[$batch['data_type']] ?? [];
        $rows = $this->repository->importRows($batchId);
        $sourceHeaders = [];
        foreach ($rows as $storedRow) {
            $source = json_decode((string) $storedRow['source_data'], true, 32, JSON_THROW_ON_ERROR);
            $sourceHeaders = array_keys($source);
            break;
        }
        foreach ($requiredFields as $required) {
            if (!isset($mapping[$required]) || !in_array($mapping[$required], $sourceHeaders, true)) {
                throw new ApiException(422, 'validation_failed', 'Map every required field to a source column.');
            }
        }
        foreach ($mapping as $field => $header) {
            if (!in_array($field, $availableFields, true) || !in_array($header, $sourceHeaders, true)) {
                throw new ApiException(422, 'validation_failed', 'The selected field mapping is not supported.');
            }
        }

        $normalizedRows = [];
        $errors = [];
        $preview = [];
        foreach ($rows as $storedRow) {
            $rowNumber = (int) $storedRow['row_number'];
            $source = json_decode((string) $storedRow['source_data'], true, 32, JSON_THROW_ON_ERROR);
            $normalized = [];
            foreach ($mapping as $field => $header) {
                $normalized[$field] = trim((string) ($source[$header] ?? ''));
            }
            $rowErrors = $this->validateMappedRow($batch['data_type'], $normalized);
            if ($batch['data_type'] === 'sales' && ($normalized['customer_id'] ?? '') !== '') {
                $customerId = filter_var($normalized['customer_id'], FILTER_VALIDATE_INT);
                $customer = $this->pdo->prepare(
                    'SELECT 1 FROM customers WHERE id = :id AND organisation_id = :organisation_id'
                );
                if ($customerId === false || $customerId < 1) {
                    $rowErrors[] = 'Customer ID must be a positive organisation customer ID.';
                } else {
                    $customer->execute(['id' => $customerId, 'organisation_id' => $organisationId]);
                    if ($customer->fetchColumn() === false) {
                        $rowErrors[] = 'Customer ID was not found in this organisation.';
                    }
                }
            }
            $normalizedRows[$rowNumber] = $normalized;
            if ($rowErrors !== []) {
                $errors[$rowNumber] = implode(' ', $rowErrors);
            }
            if (count($preview) < 10) {
                $preview[] = ['row' => $rowNumber, 'values' => $normalized, 'errors' => $rowErrors];
            }
        }
        $this->repository->validateImport($organisationId, $batchId, $mapping, $normalizedRows, $errors);
        return [
            'valid' => count($errors) === 0,
            'row_count' => count($rows),
            'error_count' => count($errors),
            'preview' => $preview,
        ];
    }

    public function commitImport(int $organisationId, int $batchId): array
    {
        $this->pdo->beginTransaction();
        try {
            $batch = $this->repository->importBatch($organisationId, $batchId, true);
            if ($batch['status'] !== 'validated') {
                throw new ApiException(409, 'import_not_validated', 'Resolve validation errors before committing this import.');
            }
            $table = self::TARGET_TABLES[$batch['data_type']] ?? null;
            if ($table === null) {
                throw new ApiException(422, 'unsupported_data_type', 'This import type is not supported.');
            }
            $rows = $this->repository->importRows($batchId);
            $count = 0;
            foreach ($rows as $row) {
                $data = json_decode((string) $row['normalized_data'], true, 32, JSON_THROW_ON_ERROR);
                $this->insertImportedRecord(
                    $table,
                    $batch['data_type'],
                    $organisationId,
                    $batchId,
                    (int) $batch['uploaded_by'],
                    $data
                );
                $count++;
            }
            $this->repository->finishImport($organisationId, $batchId, 'committed');
            $this->outbox?->enqueue(
                $organisationId,
                'import_batch',
                $batchId,
                'ImportCommitted',
                ['batch_id' => $batchId, 'data_type' => $batch['data_type'], 'record_count' => $count]
            );
            $this->pdo->commit();
            return ['status' => 'committed', 'records_imported' => $count];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function rollbackImport(int $organisationId, int $batchId): array
    {
        $this->pdo->beginTransaction();
        try {
            $batch = $this->repository->importBatch($organisationId, $batchId, true);
            if ($batch['status'] !== 'committed') {
                throw new ApiException(409, 'import_not_committed', 'Only a committed import can be rolled back.');
            }
            $this->repository->rollbackImportedRows($organisationId, $batchId, (string) $batch['data_type']);
            $this->repository->finishImport($organisationId, $batchId, 'rolled_back');
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['status' => 'rolled_back'];
    }

    public function importHistory(int $organisationId): array
    {
        return ['imports' => $this->repository->history($organisationId)];
    }

    public function templates(string $dataType): array
    {
        if (!array_key_exists($dataType, self::IMPORT_FIELDS)) {
            throw new ApiException(404, 'template_not_found', 'The requested template was not found.');
        }
        $headers = self::IMPORT_FIELDS[$dataType];
        $csv = implode(',', $headers) . "\r\n";
        $filename = $dataType . '-template.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    public function integrations(int $organisationId): array
    {
        return ['integrations' => $this->repository->integrations($organisationId)];
    }

    public function runScheduledIntegrations(): array
    {
        $outcomes = [];
        foreach ($this->repository->dueIntegrations() as $integration) {
            $outcomes[] = [
                'integration_id' => (int) $integration['id'],
                'organisation_id' => (int) $integration['organisation_id'],
                ...$this->syncIntegration((int) $integration['organisation_id'], (int) $integration['id']),
            ];
        }
        return $outcomes;
    }

    public function createIntegration(int $organisationId, int $userId, array $payload): array
    {
        $input = $this->integrationInput($payload);
        $input['encrypted_credentials'] = $this->encryptCredentials($input['credentials']);
        unset($input['credentials']);
        $id = $this->repository->createIntegration($organisationId, $userId, $input);
        return ['integration_id' => $id, 'status' => 'draft'];
    }

    public function testConnection(array $payload): array
    {
        $url = $this->validateEndpoint((string) ($payload['endpoint_url'] ?? ''));
        $credentials = $this->credentials($payload['credentials'] ?? []);
        $result = $this->probe($url, $credentials);
        return ['connected' => true, 'http_status' => $result['status'], 'records_detected' => $result['records']];
    }

    public function activateIntegration(int $organisationId, int $integrationId): array
    {
        $integration = $this->repository->integration($organisationId, $integrationId, true);
        $current = $this->repository->integration($organisationId, $integrationId);
        $url = $this->validateEndpoint((string) $integration['endpoint_url']);
        $credentials = $this->decryptCredentials((string) $integration['encrypted_credentials']);
        try {
            $result = $this->probe($url, $credentials);
        } catch (ApiException $exception) {
            $this->repository->updateIntegration($organisationId, $integrationId, [
                'endpoint_url' => $url,
                'encrypted_credentials' => $integration['encrypted_credentials'],
                'field_mappings' => json_decode((string) ($current['field_mappings'] ?? '[]'), true) ?: [],
                'sync_schedule' => (string) ($current['sync_schedule'] ?? 'manual'),
                'status' => 'error',
                'records_synced' => 0,
                'last_error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
        $this->repository->updateIntegration($organisationId, $integrationId, [
            'endpoint_url' => $url,
            'encrypted_credentials' => $integration['encrypted_credentials'],
            'field_mappings' => json_decode((string) ($current['field_mappings'] ?? '[]'), true) ?: [],
            'sync_schedule' => (string) ($current['sync_schedule'] ?? 'manual'),
            'status' => 'active',
            'records_synced' => $result['records'],
            'last_error' => null,
        ]);
        $this->repository->syncRun($integrationId, 'success', $result['records'], null);
        return ['status' => 'active', 'records_synced' => $result['records']];
    }

    public function syncIntegration(int $organisationId, int $integrationId): array
    {
        $integration = $this->repository->integration($organisationId, $integrationId, true);
        if ($integration['status'] !== 'active') {
            throw new ApiException(409, 'integration_inactive', 'Activate this integration before syncing.');
        }
        try {
            $url = $this->validateEndpoint((string) $integration['endpoint_url']);
            $credentials = $this->decryptCredentials((string) $integration['encrypted_credentials']);
            $result = $this->probe($url, $credentials);
            $status = 'success';
            $lastError = null;
            $records = $result['records'];
            $message = 'Connection check completed; no data was written to business records.';
        } catch (ApiException $exception) {
            $status = 'error';
            $lastError = $exception->getMessage();
            $records = 0;
            $message = $lastError;
        }
        $current = $this->repository->integration($organisationId, $integrationId);
        $mappings = json_decode((string) ($current['field_mappings'] ?? '[]'), true) ?: [];
        $this->repository->updateIntegration($organisationId, $integrationId, [
            'endpoint_url' => (string) $integration['endpoint_url'],
            'encrypted_credentials' => $integration['encrypted_credentials'],
            'field_mappings' => $mappings,
            'sync_schedule' => (string) ($current['sync_schedule'] ?? 'manual'),
            'status' => $status === 'success' ? 'active' : 'error',
            'records_synced' => $records,
            'last_error' => $lastError,
        ]);
        $this->repository->syncRun($integrationId, $status, $records, $lastError);
        return ['status' => $status, 'records_synced' => $records, 'message' => $message];
    }

    private function integrationInput(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $category = trim((string) ($payload['category'] ?? ''));
        $connector = trim((string) ($payload['connector_type'] ?? ''));
        $url = $this->validateEndpoint((string) ($payload['endpoint_url'] ?? ''));
        $schedule = (string) ($payload['sync_schedule'] ?? 'manual');
        $mappings = $payload['field_mappings'] ?? [];
        $credentials = $this->credentials($payload['credentials'] ?? []);
        $connectors = [
            'Odoo', 'SAP Business One', 'Sage', 'QuickBooks', 'Zoho Books', 'HubSpot',
            'Salesforce', 'Zoho CRM', 'HCM', 'SCM', 'POS', 'Generic REST API',
            'Database connection', 'File drop (CSV/XLSX)', 'Webhook',
        ];
        if ($name === '' || mb_strlen($name) > 120 || !in_array($connector, $connectors, true)
            || !in_array($schedule, ['manual', 'hourly', 'daily', 'weekly'], true) || !is_array($mappings)) {
            throw new ApiException(422, 'validation_failed', 'Check the integration name, field mappings, and sync schedule.');
        }
        return [
            'name' => $name,
            'category' => mb_substr($category, 0, 40),
            'connector_type' => mb_substr($connector, 0, 80),
            'endpoint_url' => $url,
            'sync_schedule' => $schedule,
            'field_mappings' => $mappings,
            'credentials' => $credentials,
        ];
    }

    private function credentials(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ApiException(422, 'validation_failed', 'Credentials must be submitted as a key/value object.');
        }
        $credentials = [];
        foreach ($value as $key => $secret) {
            if (!in_array($key, ['api_token', 'username', 'password'], true) || !is_string($secret)
                || strlen($secret) > 2048 || preg_match('/[\r\n\x00]/', $secret) === 1) {
                throw new ApiException(422, 'validation_failed', 'Only API token or basic authentication credentials are supported.');
            }
            if ($secret !== '') {
                $credentials[$key] = $secret;
            }
        }
        return $credentials;
    }

    private function validateEndpoint(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443) || strlen($url) > 2048) {
            throw new ApiException(422, 'unsafe_endpoint', 'Use a valid HTTPS endpoint without embedded credentials or fragments.');
        }
        $host = strtolower((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ApiException(422, 'unsafe_endpoint', 'The endpoint host must use a public IPv4 address or public DNS name.');
            }
            $addresses = [$host];
        } else {
            if (!str_contains($host, '.') || preg_match('/^[0-9.]+$/', $host) === 1
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || preg_match('/\.(?:localhost|local|internal|test|invalid|example)\.?$/i', $host) === 1) {
                throw new ApiException(422, 'unsafe_endpoint', 'Use a public DNS host name for the HTTPS endpoint.');
            }
            parse_str((string) ($parts['query'] ?? ''), $query);
            foreach (array_keys($query) as $key) {
                if (preg_match('/(?:password|token|secret|api[_-]?key|authorization)/i', (string) $key) === 1) {
                    throw new ApiException(422, 'unsafe_endpoint', 'Store credentials in the secure credential fields, not in the endpoint URL.');
                }
            }
            $addresses = gethostbynamel($host) ?: [];
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            throw new ApiException(422, 'unsafe_endpoint', 'The endpoint host could not be resolved to a public address.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ApiException(422, 'unsafe_endpoint', 'Endpoints must resolve only to public IP addresses.');
            }
        }
        return $url;
    }

    private function probe(string $url, array $credentials): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $addresses = gethostbynamel($host) ?: [];
        $address = $addresses[0] ?? null;
        if ($address === null
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new ApiException(422, 'unsafe_endpoint', 'The endpoint must resolve to a public IPv4 address.');
        }
        $response = '';
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException(503, 'connection_failed', 'The connection test could not be initialized.');
        }
        $headers = ['Accept: application/json, text/plain;q=0.9'];
        if (isset($credentials['api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $credentials['api_token'];
        } elseif (isset($credentials['username'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($credentials['username'] . ':' . ($credentials['password'] ?? ''));
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $address],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 262144) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new ApiException(502, 'connection_failed', 'The endpoint did not return a successful response.');
        }
        $decoded = json_decode($response, true);
        $records = is_array($decoded) && array_is_list($decoded) ? count($decoded) : ($response === '' ? 0 : 1);
        return ['status' => $status, 'records' => $records];
    }

    private function encryptCredentials(array $credentials): ?string
    {
        if ($credentials === []) {
            return null;
        }
        $key = hash('sha256', (string) $this->config->get('app_key'), true);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            json_encode($credentials, JSON_THROW_ON_ERROR),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'vantiq-integrations-v1'
        );
        if ($ciphertext === false) {
            throw new ApiException(500, 'credential_encryption_failed', 'Connector credentials could not be protected.');
        }
        return base64_encode($nonce . $tag . $ciphertext);
    }

    private function decryptCredentials(?string $encoded): array
    {
        if ($encoded === null || $encoded === '') {
            return [];
        }
        $binary = base64_decode($encoded, true);
        if ($binary === false || strlen($binary) < 29) {
            throw new ApiException(500, 'credential_decryption_failed', 'Stored connector credentials could not be read.');
        }
        $plaintext = openssl_decrypt(
            substr($binary, 28),
            'aes-256-gcm',
            hash('sha256', (string) $this->config->get('app_key'), true),
            OPENSSL_RAW_DATA,
            substr($binary, 0, 12),
            substr($binary, 12, 16),
            'vantiq-integrations-v1'
        );
        if ($plaintext === false) {
            throw new ApiException(500, 'credential_decryption_failed', 'Stored connector credentials could not be read.');
        }
        $credentials = json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR);
        return is_array($credentials) ? $credentials : [];
    }

    private function insertImportedRecord(
        string $table,
        string $dataType,
        int $organisationId,
        int $batchId,
        int $userId,
        array $data
    ): void
    {
        $fields = match ($dataType) {
            'sales' => ['sale_date', 'amount', 'customer_id'],
            'expenses' => ['expense_date', 'amount', 'description'],
            'customers' => ['name', 'email'],
            'suppliers' => ['name', 'contact_email'],
            'products' => ['name', 'sku', 'unit_cost', 'selling_price', 'stock_level'],
            default => throw new ApiException(422, 'unsupported_data_type', 'This import type is not supported.'),
        };
        $values = [
            'organisation_id' => $organisationId,
            'import_batch_id' => $batchId,
            'created_by' => $userId,
            'source' => 'upload',
            'data_quality_status' => 'validated',
        ];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== '') {
                $values[$field] = $data[$field];
            }
        }
        if ($dataType === 'sales') {
            $values['subtotal'] = $values['amount'];
            $values['total'] = $values['amount'];
            unset($values['amount']);
            $values['discount'] = 0;
            $values['tax'] = 0;
            $values['status'] = 'posted';
        }
        $columns = array_keys($values);
        $quoted = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));
        $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . $quoted . ') VALUES (' . $placeholders . ')');
        $statement->execute($values);
    }

    private function validateMappedRow(string $dataType, array &$row): array
    {
        $errors = [];
        foreach (self::IMPORT_REQUIRED[$dataType] as $field) {
            if (!isset($row[$field]) || trim((string) $row[$field]) === '') {
                $errors[] = 'A required field is empty.';
                break;
            }
        }
        if (in_array($dataType, ['sales', 'expenses'], true)) {
            $dateField = $dataType === 'sales' ? 'sale_date' : 'expense_date';
            if (isset($row[$dateField])) {
                $timestamp = strtotime((string) $row[$dateField]);
                if ($timestamp === false) {
                    $errors[] = 'Use a recognizable date value.';
                } else {
                    $row[$dateField] = gmdate('Y-m-d H:i:s', $timestamp);
                }
            }
            if (!isset($row['amount']) || !is_numeric($row['amount']) || (float) $row['amount'] < 0
                || (float) $row['amount'] > 9999999999999999) {
                $errors[] = 'Amount must be a non-negative number.';
            }
        }
        if ($dataType === 'products') {
            foreach (['unit_cost', 'selling_price', 'stock_level'] as $field) {
                if (isset($row[$field]) && $row[$field] !== '' && (!is_numeric($row[$field])
                    || (float) $row[$field] < 0 || (float) $row[$field] > 9999999999999999)) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a non-negative number.';
                }
            }
            if (isset($row['stock_level']) && $row['stock_level'] !== ''
                && filter_var($row['stock_level'], FILTER_VALIDATE_INT) === false) {
                $errors[] = 'Stock level must be a whole number.';
            }
        }
        if (in_array($dataType, ['customers', 'suppliers'], true)) {
            $email = $row[$dataType === 'customers' ? 'email' : 'contact_email'] ?? '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Enter a valid email address.';
            }
        }
        foreach (['name' => 180, 'sku' => 80, 'description' => 255, 'email' => 254, 'contact_email' => 254] as $field => $limit) {
            if (isset($row[$field]) && mb_strlen((string) $row[$field]) > $limit) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is too long.';
            }
        }
        return $errors;
    }

    private function validateUpload(array $file, array $extensions): array
    {
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiException(413, 'file_size_invalid', 'The PHP upload limit was exceeded; configure PHP to accept files up to 15 MB.');
        }
        if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new ApiException(422, 'upload_failed', 'Choose a file that finished uploading successfully.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw new ApiException(413, 'file_size_invalid', 'Files must be between 1 byte and 15 MB.');
        }
        $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions, true) || $name === '' || strlen($name) > 255) {
            throw new ApiException(422, 'file_type_invalid', 'This file type is not supported.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $allowed = match ($extension) {
            'pdf' => ['application/pdf'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            'txt' => ['text/plain'],
            default => [],
        };
        if (!is_string($mime) || !in_array($mime, $allowed, true)) {
            throw new ApiException(422, 'file_type_invalid', 'The uploaded file content does not match an allowed file type.');
        }
        if (in_array($extension, ['docx', 'xlsx'], true) && file_get_contents((string) $file['tmp_name'], false, null, 0, 2) !== 'PK') {
            throw new ApiException(422, 'file_type_invalid', 'The Office document package is invalid.');
        }
        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $this->assertOfficeArchiveSafe((string) $file['tmp_name'], $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml');
        }
        return [$name, $extension, $mime];
    }

    private function assertOfficeArchiveSafe(string $path, string $requiredEntry): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ApiException(503, 'zip_extension_unavailable', 'Enable the PHP ZIP extension to process Office files.');
        }
        $archive = new \ZipArchive();
        if ($archive->open($path) !== true || $archive->numFiles > 2000) {
            throw new ApiException(422, 'invalid_office_file', 'The Office document package is invalid.');
        }
        $totalSize = 0;
        $containsRequiredEntry = false;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);
            if (!is_array($entry)) {
                $archive->close();
                throw new ApiException(422, 'invalid_office_file', 'The Office document package is invalid.');
            }
            $entryName = (string) ($entry['name'] ?? '');
            $size = (int) ($entry['size'] ?? 0);
            $compressed = max(1, (int) ($entry['comp_size'] ?? 0));
            $totalSize += $size;
            if ($entryName === $requiredEntry) {
                $containsRequiredEntry = true;
            }
            if ($size > 25000000 || $totalSize > 100000000 || $size / $compressed > 100) {
                $archive->close();
                throw new ApiException(413, 'office_file_too_large', 'The Office document expands beyond the supported size limit.');
            }
        }
        $archive->close();
        if (!$containsRequiredEntry) {
            throw new ApiException(422, 'invalid_office_file', 'The Office document does not contain its required document content.');
        }
    }

    private function readTabularFile(string $path, string $extension): array
    {
        if ($extension === 'xlsx') {
            if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
                throw new ApiException(503, 'spreadsheet_parser_unavailable', 'XLSX support requires the configured spreadsheet parser.');
            }
            $this->assertOfficeArchiveSafe($path, 'xl/workbook.xml');
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            return $spreadsheet->getActiveSheet()->toArray('', true, true, false);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ApiException(422, 'file_unreadable', 'The uploaded CSV file could not be read.');
        }
        $rows = [];
        try {
            while (($row = fgetcsv($handle, 1048576, ',', '"', '\\')) !== false) {
                if (count($rows) > 5000) {
                    throw new ApiException(413, 'too_many_rows', 'Imports are limited to 5,000 rows.');
                }
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }
        return $rows;
    }

    private function extractText(string $path, string $extension, array $context = []): array
    {
        try {
            if ($extension === 'txt' || $extension === 'csv') {
                $text = file_get_contents($path);
                return [is_string($text) ? mb_substr($text, 0, 200000) : '', is_string($text) ? 'available' : 'failed'];
            }
            if ($extension === 'pdf' && class_exists(\Smalot\PdfParser\Parser::class)) {
                $text = (new \Smalot\PdfParser\Parser())->parseFile($path)->getText();
                return [mb_substr($text, 0, 200000), 'available'];
            }
            if ($extension === 'docx' && class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
                $document = \PhpOffice\PhpWord\IOFactory::load($path);
                $text = '';
                foreach ($document->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= (string) $element->getText() . "\n";
                        }
                    }
                }
                return [mb_substr($text, 0, 200000), 'available'];
            }
        } catch (Throwable $exception) {
            $this->logger->exception('Document text extraction failed.', $exception, $context);
            return ['', 'failed'];
        }
        return ['', 'unsupported'];
    }

    private function privateDirectory(string $type): string
    {
        $root = rtrim((string) $this->config->get('private_storage_path'), DIRECTORY_SEPARATOR);
        if ($root === '' || $root === dirname(__DIR__, 3)) {
            throw new ApiException(500, 'storage_configuration_invalid', 'Private file storage is not configured outside the application root.');
        }
        $directory = $root . DIRECTORY_SEPARATOR . $type;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ApiException(500, 'storage_unavailable', 'Private file storage is unavailable.');
        }
        if (!chmod($directory, 0700)) {
            throw new ApiException(500, 'storage_permissions_failed', 'Private storage permissions could not be applied.');
        }
        return $directory;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
