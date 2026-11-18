<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apply = in_array('--apply', $argv, true);
$limit = 1000;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match) === 1) {
        $limit = max(1, min(10000, (int) $match[1]));
    }
}

try {
    $config = Config::load(dirname(__DIR__));
    $pdo = Database::connect($config);
    $statement = $pdo->prepare(
        'SELECT id, organisation_id, stored_name FROM documents
         WHERE content_sha256 IS NULL ORDER BY id ASC LIMIT :limit'
    );
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    $documents = $statement->fetchAll();
    if ($documents === []) {
        fwrite(STDOUT, json_encode([
            'mode' => $apply ? 'apply' : 'dry-run',
            'scanned' => 0,
            'eligible' => 0,
            'updated' => 0,
            'historical_duplicates_left_unchanged' => 0,
            'missing_or_unreadable_files' => 0,
            'next_step' => 'complete',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    $storageRoot = rtrim((string) $config->get('private_storage_path'), DIRECTORY_SEPARATOR);
    $projectRoot = realpath(dirname(__DIR__));
    $resolvedRoot = realpath($storageRoot);
    $projectPrefix = $projectRoot === false ? '' : rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($storageRoot === '' || $resolvedRoot === false || $projectPrefix === ''
        || str_starts_with(strtolower($resolvedRoot . DIRECTORY_SEPARATOR), strtolower($projectPrefix))) {
        throw new RuntimeException('Private document storage is not configured outside the application root.');
    }
    $documentDirectory = realpath($resolvedRoot . DIRECTORY_SEPARATOR . 'documents');
    if ($documentDirectory !== false
        && str_starts_with(strtolower($documentDirectory . DIRECTORY_SEPARATOR), strtolower($projectPrefix))) {
        throw new RuntimeException('Private document files must remain outside the application root.');
    }
    $eligible = 0;
    $missing = 0;
    $duplicates = 0;
    $updated = 0;

    foreach ($documents as $document) {
        $storedName = (string) $document['stored_name'];
        if (preg_match('/^[a-f0-9]{64}\.(?:docx|pdf|xlsx|csv|txt)$/i', $storedName) !== 1
            || $documentDirectory === false) {
            $missing++;
            continue;
        }
        $path = realpath($documentDirectory . DIRECTORY_SEPARATOR . $storedName);
        if ($path === false || !str_starts_with($path, $documentDirectory . DIRECTORY_SEPARATOR) || !is_file($path)) {
            $missing++;
            continue;
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            $missing++;
            continue;
        }
        $eligible++;
        if (!$apply) {
            continue;
        }

        $existing = $pdo->prepare(
            'SELECT 1 FROM documents
             WHERE organisation_id = :organisation_id AND content_sha256 = :content_sha256 LIMIT 1'
        );
        $existing->execute([
            'organisation_id' => $document['organisation_id'],
            'content_sha256' => $hash,
        ]);
        if ($existing->fetchColumn() !== false) {
            $duplicates++;
            continue;
        }
        try {
            $update = $pdo->prepare(
                'UPDATE documents SET content_sha256 = :content_sha256
                 WHERE id = :id AND organisation_id = :organisation_id AND content_sha256 IS NULL'
            );
            $update->execute([
                'content_sha256' => $hash,
                'id' => $document['id'],
                'organisation_id' => $document['organisation_id'],
            ]);
            $updated += $update->rowCount();
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $duplicates++;
        }
    }

    fwrite(STDOUT, json_encode([
        'mode' => $apply ? 'apply' : 'dry-run',
        'scanned' => count($documents),
        'eligible' => $eligible,
        'updated' => $updated,
        'historical_duplicates_left_unchanged' => $duplicates,
        'missing_or_unreadable_files' => $missing,
        'next_step' => count($documents) === $limit ? 'rerun to continue' : 'complete',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $exception) {
    error_log(json_encode([
        'message' => 'Document fingerprint backfill failed.',
        'exception' => $exception::class,
        'time' => gmdate(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, 'Fingerprint backfill failed; inspect the server error log.' . PHP_EOL);
    exit(1);
}
