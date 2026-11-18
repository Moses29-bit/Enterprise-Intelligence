<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Http\ApiException;
use Vantiq\Http\Request;
use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\AuditRepository;
use Vantiq\Repository\DataWorkspaceRepository;
use Vantiq\Repository\OutboxRepository;
use Vantiq\Repository\OrganisationRepository;
use Vantiq\Service\DataWorkspaceService;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$multipart = new Request(
    'POST',
    '/documents/upload',
    ['Content-Type' => 'multipart/form-data; boundary=example', 'Content-Length' => '16777217'],
    [],
    '',
    '127.0.0.1'
);
$assert($multipart->isMultipart(), 'Multipart request detection failed.');
$assert($multipart->contentLength() === 16777217, 'Content-length parsing failed.');

$root = dirname(__DIR__);
$config = Config::load($root);
$storagePath = strtolower((string) $config->get('private_storage_path'));
$webRoot = strtolower(dirname(realpath($root) ?: $root));
$assert(
    !str_starts_with($storagePath, rtrim($webRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR),
    'Private storage resolves inside the web root.'
);

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE organisations (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)');
$pdo->exec("INSERT INTO organisations (id, name, status) VALUES (1, 'Active', 'active'), (2, 'Paused', 'suspended')");
$activeOrganisations = (new OrganisationRepository($pdo))->activeOrganisations();
$assert(
    count($activeOrganisations) === 1
        && (int) $activeOrganisations[0]['id'] === 1
        && $activeOrganisations[0]['name'] === 'Active',
    'Platform organisation selection did not restrict results to active tenants.'
);
$service = new DataWorkspaceService(new DataWorkspaceRepository($pdo), $config, $pdo, new Logger());
foreach (['https://127.0.0.1/data', 'https://localhost/data', 'http://example.com/data', 'https://127.1/data'] as $url) {
    try {
        $service->testConnection(['endpoint_url' => $url, 'credentials' => []]);
    } catch (ApiException $exception) {
        $assert($exception->status === 422, 'An unsafe endpoint returned the wrong status.');
        continue;
    }
    throw new RuntimeException('An unsafe connector endpoint was accepted.');
}

if (getenv('VANTIQ_PUBLIC_CONNECTOR_SMOKE') === '1') {
    $connection = $service->testConnection([
        'endpoint_url' => 'https://jsonplaceholder.typicode.com/posts/1',
        'credentials' => [],
    ]);
    $assert($connection['connected'] && $connection['records_detected'] === 1, 'The public REST connector test failed.');
}

if (extension_loaded('zip')) {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vantiq-workspace-' . bin2hex(random_bytes(5));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create temporary document fixtures.');
    }
    $docx = $directory . DIRECTORY_SEPARATOR . 'sample.docx';
    $xlsx = $directory . DIRECTORY_SEPARATOR . 'sample.xlsx';
    $csv = $directory . DIRECTORY_SEPARATOR . 'sample.csv';
    try {
        $word = new PhpOffice\PhpWord\PhpWord();
        $word->addSection()->addText('VANTIQ document extraction sample');
        PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($docx);

        $book = new PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray([['expense_date', 'amount'], ['2026-01-01', 42.5]]);
        (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($xlsx);
        file_put_contents($csv, "expense_date,amount\n2026-01-01,42.5\n");

        $serviceReflection = new ReflectionClass($service);
        $extractText = $serviceReflection->getMethod('extractText');
        $extractText->setAccessible(true);
        [$text, $status] = $extractText->invoke($service, $docx, 'docx');
        $assert(
            $status === 'available' && str_contains($text, 'VANTIQ document extraction sample'),
            'DOCX extraction failed.'
        );

        $readTabularFile = $serviceReflection->getMethod('readTabularFile');
        $readTabularFile->setAccessible(true);
        $assert(count($readTabularFile->invoke($service, $xlsx, 'xlsx')) === 2, 'XLSX reading failed.');
        $assert(count($readTabularFile->invoke($service, $csv, 'csv')) === 2, 'CSV reading failed.');
    } finally {
        foreach ([$docx, $xlsx, $csv] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Unable to remove a temporary document fixture.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Unable to remove the temporary fixture directory.');
        }
    }
} else {
    fwrite(STDOUT, "DOCX/XLSX parser smoke checks skipped: PHP ZIP extension is disabled." . PHP_EOL);
}

if (class_exists(Smalot\PdfParser\Parser::class)) {
    $pdfDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vantiq-pdf-' . bin2hex(random_bytes(5));
    if (!mkdir($pdfDirectory, 0700)) {
        throw new RuntimeException('Unable to create the temporary PDF fixture directory.');
    }
    $pdfPath = $pdfDirectory . DIRECTORY_SEPARATOR . 'sample.pdf';
    $stream = "BT /F1 12 Tf 72 720 Td (VANTIQ PDF extraction sample) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    try {
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n";
        $pdf .= $xrefOffset . "\n%%EOF\n";
        file_put_contents($pdfPath, $pdf);
        $pdfReflection = new ReflectionClass($service);
        $extractPdfText = $pdfReflection->getMethod('extractText');
        $extractPdfText->setAccessible(true);
        [$text, $status] = $extractPdfText->invoke($service, $pdfPath, 'pdf');
        $assert($status === 'available' && str_contains($text, 'VANTIQ PDF extraction sample'), 'PDF extraction failed.');
    } finally {
        if (is_file($pdfPath) && !unlink($pdfPath)) {
            throw new RuntimeException('Unable to remove the temporary PDF fixture.');
        }
        if (!rmdir($pdfDirectory)) {
            throw new RuntimeException('Unable to remove the temporary PDF fixture directory.');
        }
    }
}

$pdo->exec(<<<'SQL'
CREATE TABLE documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL,
    version_group TEXT NOT NULL,
    version_number INTEGER NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    content_sha256 TEXT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    category TEXT NOT NULL,
    extracted_text TEXT NOT NULL,
    extraction_status TEXT NOT NULL,
    uploaded_by INTEGER NOT NULL,
    reviewed_at TEXT NULL,
    UNIQUE (organisation_id, content_sha256)
);
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    action_type TEXT NOT NULL,
    entity_name TEXT NOT NULL,
    details TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE outbox_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT UNIQUE NOT NULL,
    organisation_id INTEGER NOT NULL,
    aggregate_type TEXT NOT NULL,
    aggregate_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    event_version INTEGER NOT NULL,
    event_payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TEXT,
    lock_token TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TEXT,
    last_error TEXT
);
SQL);
$documentRepository = new DataWorkspaceRepository($pdo, new AuditRepository($pdo), new OutboxRepository($pdo));
$document = $documentRepository->createDocument([
    'organisation_id' => 1,
    'version_group' => 'version-group',
    'version_number' => 1,
    'original_name' => 'report.csv',
    'stored_name' => 'random-name.csv',
    'content_sha256' => str_repeat('a', 64),
    'mime_type' => 'text/csv',
    'file_size' => 18,
    'category' => 'other',
    'extracted_text' => 'source,value',
    'extraction_status' => 'available',
    'uploaded_by' => 7,
]);
$audit = $pdo->query('SELECT organisation_id, user_id, action_type, details FROM audit_logs')->fetch(PDO::FETCH_ASSOC);
$assert(
    $document['id'] > 0
        && (int) $audit['organisation_id'] === 1
        && (int) $audit['user_id'] === 7
        && $audit['action_type'] === 'document.uploaded'
        && (int) json_decode($audit['details'], true)['document_id'] === $document['id'],
    'Document persistence and its tenant-scoped business event were not committed together.'
);
$documentEvents = $pdo->query('SELECT event_type FROM outbox_events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$assert(
    $documentEvents === ['DocumentUploaded', 'DocumentAnalysisCompleted'],
    'Document upload and successful extraction were not recorded as outbox events.'
);
$documentRepository->markDocumentReviewed(1, 7, $document['id']);
$reviewEvent = $pdo->query("SELECT action_type FROM audit_logs WHERE action_type = 'document.reviewed'")->fetchColumn();
$assert($reviewEvent === 'document.reviewed', 'Document review did not publish its business event in the audit transaction.');
$outboxReviewEvent = $pdo->query("SELECT event_type FROM outbox_events WHERE event_type = 'DocumentReviewed'")->fetchColumn();
$assert($outboxReviewEvent === 'DocumentReviewed', 'Document review did not add its transactional outbox event.');
$duplicate = $documentRepository->createDocument([
    'organisation_id' => 1,
    'version_group' => 'different-file-name',
    'version_number' => 1,
    'original_name' => 'copy.csv',
    'stored_name' => 'duplicate-content.csv',
    'content_sha256' => str_repeat('a', 64),
    'mime_type' => 'text/csv',
    'file_size' => 18,
    'category' => 'other',
    'extracted_text' => 'source,value',
    'extraction_status' => 'available',
    'uploaded_by' => 7,
]);
$assert(
    $duplicate['duplicate'] === true && $duplicate['id'] === $document['id'],
    'Identical organisation document content was not deduplicated.'
);
$assert((int) $pdo->query('SELECT COUNT(*) FROM outbox_events')->fetchColumn() === 3, 'A duplicate document must not create another business event.');
$pdo->exec(<<<'SQL'
CREATE TRIGGER reject_document_audit BEFORE INSERT ON audit_logs
BEGIN
    SELECT RAISE(ABORT, 'audit unavailable');
END;
SQL);
try {
    $documentRepository->createDocument([
        'organisation_id' => 1,
        'version_group' => 'version-group',
        'version_number' => 2,
        'original_name' => 'report.csv',
        'stored_name' => 'another-random-name.csv',
        'content_sha256' => str_repeat('b', 64),
        'mime_type' => 'text/csv',
        'file_size' => 18,
        'category' => 'other',
        'extracted_text' => 'source,value',
        'extraction_status' => 'available',
        'uploaded_by' => 7,
    ]);
    throw new RuntimeException('A document was committed without its corresponding business event.');
} catch (PDOException) {
    $documentCount = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    $assert($documentCount === 1, 'Document creation was not rolled back when its audit event failed.');
}
$pdo->exec('DROP TRIGGER reject_document_audit');
$pdo->exec(<<<'SQL'
CREATE TRIGGER reject_document_outbox BEFORE INSERT ON outbox_events
BEGIN
    SELECT RAISE(ABORT, 'outbox unavailable');
END;
SQL);
$auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
try {
    $documentRepository->createDocument([
        'organisation_id' => 1,
        'version_group' => 'version-group',
        'version_number' => 3,
        'original_name' => 'report-new.csv',
        'stored_name' => 'outbox-failure.csv',
        'content_sha256' => str_repeat('c', 64),
        'mime_type' => 'text/csv',
        'file_size' => 18,
        'category' => 'other',
        'extracted_text' => 'source,value',
        'extraction_status' => 'available',
        'uploaded_by' => 7,
    ]);
    throw new RuntimeException('A document was committed without its corresponding outbox event.');
} catch (PDOException) {
    $assert((int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn() === 1, 'Document creation was not rolled back when outbox insertion failed.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $auditCount, 'Document audit event was not rolled back with outbox failure.');
}

fwrite(STDOUT, $checks . " data-workspace checks passed." . PHP_EOL);
