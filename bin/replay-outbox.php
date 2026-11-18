<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;
use Vantiq\Repository\OutboxRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$eventId = (string) ($argv[1] ?? '');
if (preg_match('/^[a-f0-9-]{36}$/i', $eventId) !== 1) {
    fwrite(STDERR, 'Usage: php bin/replay-outbox.php <failed-event-uuid>' . PHP_EOL);
    exit(2);
}

try {
    $pdo = Database::connect(Config::load(dirname(__DIR__)));
    $requeued = (new OutboxRepository($pdo))->requeueFailed($eventId);
    if (!$requeued) {
        fwrite(STDERR, 'No failed event matched that identifier.' . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, "Failed event requeued.\n");
} catch (Throwable $exception) {
    error_log(json_encode([
        'message' => 'Outbox event replay failed.',
        'exception' => $exception::class,
        'event_id' => $eventId,
        'time' => gmdate(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, 'Replay failed; inspect the server error log.' . PHP_EOL);
    exit(1);
}
