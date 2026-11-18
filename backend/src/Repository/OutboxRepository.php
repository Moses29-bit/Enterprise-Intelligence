<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;
use RuntimeException;

final class OutboxRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(
        int $organisationId,
        string $aggregateType,
        string|int $aggregateId,
        string $eventType,
        array $payload = []
    ): string {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Outbox events must be recorded inside the business transaction.');
        }

        $eventId = self::uuid();
        $statement = $this->pdo->prepare(
            'INSERT INTO outbox_events
                (event_id, organisation_id, aggregate_type, aggregate_id, event_type, event_version, event_payload)
             VALUES
                (:event_id, :organisation_id, :aggregate_type, :aggregate_id, :event_type, 1, :event_payload)'
        );
        $statement->execute([
            'event_id' => $eventId,
            'organisation_id' => $organisationId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'event_type' => $eventType,
            'event_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        return $eventId;
    }

    public function claimNext(?int $organisationId = null): ?array
    {
        $this->recoverExpiredClaims($organisationId);
        $this->pdo->beginTransaction();
        try {
            $lockSuffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sql =
                'SELECT id, event_id, organisation_id, aggregate_type, aggregate_id, event_type,
                        event_version, event_payload, attempts
                 FROM outbox_events
                 WHERE status = "pending" AND available_at <= CURRENT_TIMESTAMP';
            if ($organisationId !== null) {
                $sql .= ' AND organisation_id = :organisation_id';
            }
            $statement = $this->pdo->prepare($sql . ' ORDER BY id ASC LIMIT 1' . $lockSuffix);
            if ($organisationId !== null) {
                $statement->bindValue('organisation_id', $organisationId, PDO::PARAM_INT);
            }
            $statement->execute();
            $event = $statement->fetch();
            if (!is_array($event)) {
                $this->pdo->commit();
                return null;
            }

            $token = self::uuid();
            $claim = $this->pdo->prepare(
                'UPDATE outbox_events
                 SET status = "processing", attempts = attempts + 1, locked_at = CURRENT_TIMESTAMP,
                     lock_token = :lock_token
                 WHERE id = :id AND status = "pending"'
            );
            $claim->execute(['lock_token' => $token, 'id' => $event['id']]);
            if ($claim->rowCount() !== 1) {
                $this->pdo->commit();
                return null;
            }
            $event['attempts'] = (int) $event['attempts'] + 1;
            $event['lock_token'] = $token;
            $this->pdo->commit();
            return $event;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function publish(array $event, string $summary, array $modules): void
    {
        $this->pdo->beginTransaction();
        try {
            $lockSuffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $current = $this->pdo->prepare(
                'SELECT id FROM outbox_events
                 WHERE id = :id AND status = "processing" AND lock_token = :lock_token' . $lockSuffix
            );
            $current->execute(['id' => $event['id'], 'lock_token' => $event['lock_token']]);
            if ($current->fetchColumn() === false) {
                throw new RuntimeException('The outbox claim is no longer owned by this worker.');
            }

            $notificationSql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? 'INSERT INTO event_notifications
                    (outbox_id, event_id, organisation_id, event_type, summary, modules)
                   VALUES
                    (:outbox_id, :event_id, :organisation_id, :event_type, :summary, :modules)
                   ON DUPLICATE KEY UPDATE outbox_id = VALUES(outbox_id)'
                : 'INSERT OR IGNORE INTO event_notifications
                    (outbox_id, event_id, organisation_id, event_type, summary, modules)
                   VALUES
                    (:outbox_id, :event_id, :organisation_id, :event_type, :summary, :modules)';
            $notification = $this->pdo->prepare($notificationSql);
            $notification->execute([
                'outbox_id' => $event['id'],
                'event_id' => $event['event_id'],
                'organisation_id' => $event['organisation_id'],
                'event_type' => $event['event_type'],
                'summary' => $summary,
                'modules' => json_encode(array_values(array_unique($modules)), JSON_THROW_ON_ERROR),
            ]);

            $complete = $this->pdo->prepare(
                'UPDATE outbox_events
                 SET status = "published", processed_at = CURRENT_TIMESTAMP, locked_at = NULL,
                     lock_token = NULL, last_error = NULL
                 WHERE id = :id AND status = "processing" AND lock_token = :lock_token'
            );
            $complete->execute(['id' => $event['id'], 'lock_token' => $event['lock_token']]);
            if ($complete->rowCount() !== 1) {
                throw new RuntimeException('The outbox claim changed before publication completed.');
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function fail(array $event, string $diagnosticReference): void
    {
        $attempts = (int) $event['attempts'];
        $permanentlyFailed = $attempts >= 8;
        $delay = min(3600, 5 * (2 ** max(0, $attempts - 1)));
        $availableAt = gmdate('Y-m-d H:i:s', time() + $delay);
        $statement = $this->pdo->prepare(
            'UPDATE outbox_events
             SET status = :status, available_at = :available_at, locked_at = NULL, lock_token = NULL,
                 last_error = :last_error
             WHERE id = :id AND status = "processing" AND lock_token = :lock_token'
        );
        $statement->execute([
            'status' => $permanentlyFailed ? 'failed' : 'pending',
            'available_at' => $availableAt,
            'last_error' => 'Processing error; reference ' . $diagnosticReference,
            'id' => $event['id'],
            'lock_token' => $event['lock_token'],
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The outbox event could not be moved to its retry state.');
        }
    }

    public function requeueFailed(string $eventId, ?int $organisationId = null): bool
    {
        $sql =
            'UPDATE outbox_events
             SET status = "pending", attempts = 0, available_at = CURRENT_TIMESTAMP,
                 locked_at = NULL, lock_token = NULL, processed_at = NULL, last_error = NULL
             WHERE event_id = :event_id AND status = "failed"';
        if ($organisationId !== null) {
            $sql .= ' AND organisation_id = :organisation_id';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('event_id', $eventId);
        if ($organisationId !== null) {
            $statement->bindValue('organisation_id', $organisationId, PDO::PARAM_INT);
        }
        $statement->execute();
        return $statement->rowCount() === 1;
    }

    public function failedEvents(int $organisationId, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT event_id, aggregate_type, aggregate_id, event_type, attempts, last_error, created_at
             FROM outbox_events
             WHERE organisation_id = :organisation_id AND status = "failed"
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue('organisation_id', $organisationId, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function notificationsAfter(int $organisationId, int $sequence, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, event_id, event_type, summary, modules, created_at
             FROM event_notifications
             WHERE organisation_id = :organisation_id AND id > :sequence
             ORDER BY id ASC LIMIT :limit'
        );
        $statement->bindValue('organisation_id', $organisationId, PDO::PARAM_INT);
        $statement->bindValue('sequence', $sequence, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();
        $notifications = $statement->fetchAll();
        foreach ($notifications as &$notification) {
            $notification['modules'] = json_decode((string) $notification['modules'], true, 16, JSON_THROW_ON_ERROR);
        }
        unset($notification);
        return $notifications;
    }

    private function recoverExpiredClaims(?int $organisationId): void
    {
        $staleLockPredicate = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'locked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE)'
            : "locked_at < datetime('now', '-5 minutes')";
        $sql =
            'UPDATE outbox_events
             SET status = CASE WHEN attempts >= 8 THEN "failed" ELSE "pending" END,
                 available_at = CURRENT_TIMESTAMP, locked_at = NULL, lock_token = NULL,
                 last_error = CASE WHEN attempts >= 8 THEN "Worker claim expired after final attempt" ELSE last_error END
             WHERE status = "processing" AND ' . $staleLockPredicate;
        if ($organisationId !== null) {
            $sql .= ' AND organisation_id = :organisation_id';
        }
        $statement = $this->pdo->prepare($sql);
        if ($organisationId !== null) {
            $statement->bindValue('organisation_id', $organisationId, PDO::PARAM_INT);
        }
        $statement->execute();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
