<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;
use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\OutboxRepository;
use Vantiq\Service\OutboxDispatcher;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$once = in_array('--once', $argv, true);
$limit = 50;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match) === 1) {
        $limit = max(1, min(500, (int) $match[1]));
    }
}

$logger = new Logger();
try {
    $pdo = Database::connect(Config::load(dirname(__DIR__)));
    $dispatcher = new OutboxDispatcher(new OutboxRepository($pdo), $logger);
    do {
        $result = $dispatcher->runOnce($limit);
        if ($once) {
            fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            break;
        }
        if ($result['published'] === 0 && $result['failed'] === 0) {
            sleep(2);
        }
    } while (true);
} catch (Throwable $exception) {
    $logger->exception('Outbox worker stopped', $exception, ['operation' => 'bin/process-outbox.php']);
    fwrite(STDERR, 'Outbox worker stopped; inspect the server error log for its reference ID.' . PHP_EOL);
    exit(1);
}
