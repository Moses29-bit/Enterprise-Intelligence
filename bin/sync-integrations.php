<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;
use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\DataWorkspaceRepository;
use Vantiq\Service\DataWorkspaceService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$logger = new Logger();

try {
    $root = dirname(__DIR__);
    $config = Config::load($root);
    $pdo = Database::connect($config);
    $service = new DataWorkspaceService(new DataWorkspaceRepository($pdo), $config, $pdo, $logger);
    $results = $service->runScheduledIntegrations();
    fwrite(STDOUT, json_encode(['processed' => count($results), 'integrations' => $results], JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $exception) {
    $logger->error('Scheduled integration sync failed', ['exception' => $exception::class]);
    fwrite(STDERR, 'Scheduled integration sync failed.' . PHP_EOL);
    exit(1);
}
