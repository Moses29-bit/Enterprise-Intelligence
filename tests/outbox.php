<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\OutboxRepository;
use Vantiq\Service\OutboxDispatcher;

$pdo = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE outbox_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT UNIQUE NOT NULL,
        organisation_id INTEGER NOT NULL,
        aggregate_type TEXT NOT NULL,
        aggregate_id TEXT NOT NULL,
        event_type TEXT NOT NULL,
        event_version INTEGER NOT NULL,
        event_payload TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "pending",
        attempts INTEGER NOT NULL DEFAULT 0,
        available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at TEXT,
        lock_token TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at TEXT,
        last_error TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE event_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        outbox_id INTEGER UNIQUE NOT NULL,
        event_id TEXT UNIQUE NOT NULL,
        organisation_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        summary TEXT NOT NULL,
        modules TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

$outbox = new OutboxRepository($pdo);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

try {
    $outbox->enqueue(1, 'sale', 1, 'SaleCreated');
    throw new RuntimeException('Outbox accepted an event outside a transaction.');
} catch (RuntimeException $exception) {
    $assert(
        $exception->getMessage() === 'Outbox events must be recorded inside the business transaction.',
        'Outbox must require the business transaction.'
    );
}

$pdo->beginTransaction();
$outbox->enqueue(1, 'sale', 99, 'SaleCreated', ['sale_id' => 99]);
$pdo->rollBack();
$assert((int) $pdo->query('SELECT COUNT(*) FROM outbox_events')->fetchColumn() === 0, 'Rolled-back business data must not leave an event.');

$pdo->beginTransaction();
$outbox->enqueue(1, 'sale', 100, 'SaleCreated', ['sale_id' => 100]);
$pdo->commit();
$dispatcher = new OutboxDispatcher($outbox, new Logger());
$result = $dispatcher->runOnce();
$assert($result === ['published' => 1, 'failed' => 0], 'A committed event should be published.');
$notifications = $outbox->notificationsAfter(1, 0);
$assert(count($notifications) === 1, 'The notification should be persisted once.');
$assert($notifications[0]['modules'] === ['command', 'overview', 'finance', 'operations', 'reports', 'forecast'], 'Notifications should contain module dependencies.');
$assert($outbox->notificationsAfter(2, 0) === [], 'Notifications must remain tenant scoped.');
$assert($dispatcher->runOnce() === ['published' => 0, 'failed' => 0], 'Published events must not be reprocessed.');

$pdo->beginTransaction();
$outbox->enqueue(2, 'expense', 101, 'ExpenseCreated');
$pdo->commit();
$claim = $outbox->claimNext();
$assert(is_array($claim), 'A queued event should be claimable.');
$outbox->fail($claim, 'test-reference');
$failedStatus = $pdo->query('SELECT status, attempts, last_error FROM outbox_events WHERE aggregate_id = "101"')->fetch();
$assert($failedStatus['status'] === 'pending' && (int) $failedStatus['attempts'] === 1, 'A failed event should retry with an incremented attempt.');
$assert(str_contains($failedStatus['last_error'], 'test-reference'), 'Retry diagnostics should retain a reference ID.');

$pdo->beginTransaction();
$outbox->enqueue(3, 'product', 102, 'StockAdjusted');
$pdo->commit();
$pdo->exec('UPDATE outbox_events SET attempts = 7 WHERE aggregate_id = "102"');
$claim = $outbox->claimNext();
$outbox->fail($claim, 'final-reference');
$finalStatus = $pdo->query('SELECT status FROM outbox_events WHERE aggregate_id = "102"')->fetchColumn();
$assert($finalStatus === 'failed', 'The final failed attempt should be dead-lettered.');
$failedEvents = $outbox->failedEvents(3);
$assert(count($failedEvents) === 1 && str_contains($failedEvents[0]['last_error'], 'final-reference'), 'Authorised tenant diagnostics should retain failure references.');
$assert($outbox->failedEvents(4) === [], 'Failed event diagnostics must be tenant scoped.');
$failedEventId = (string) $pdo->query('SELECT event_id FROM outbox_events WHERE aggregate_id = "102"')->fetchColumn();
$assert(!$outbox->requeueFailed($failedEventId, 4), 'A tenant must not replay another organisation event.');
$assert($outbox->requeueFailed($failedEventId, 3), 'An organisation administrator should be able to replay a failed event.');
$replayedStatus = $pdo->query('SELECT status, attempts FROM outbox_events WHERE aggregate_id = "102"')->fetch();
$assert($replayedStatus['status'] === 'pending' && (int) $replayedStatus['attempts'] === 0, 'Replaying a failed event should reset processing state only.');

fwrite(STDOUT, "Outbox checks passed: {$assertions} assertions.\n");
