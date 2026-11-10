<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;

final class LoginAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isLocked(array $bucketHashes, int $now): bool
    {
        foreach ($bucketHashes as $bucketHash) {
            $statement = $this->pdo->prepare(
                'SELECT locked_until FROM login_attempts WHERE bucket_hash = :bucket_hash'
            );
            $statement->execute(['bucket_hash' => $bucketHash]);
            $lockedUntil = $statement->fetchColumn();
            if ($lockedUntil !== false && $lockedUntil !== null && strtotime((string) $lockedUntil) > $now) {
                return true;
            }
        }
        return false;
    }

    public function fail(array $bucketHashes, int $now, int $windowSeconds, int $threshold, int $lockSeconds): void
    {
        $cleanup = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE updated_at < :cutoff'
        );
        $cleanup->execute(['cutoff' => gmdate('Y-m-d H:i:s', $now - 86400)]);

        foreach ($bucketHashes as $bucketHash) {
            $this->pdo->beginTransaction();
            try {
                $nowText = gmdate('Y-m-d H:i:s', $now);
                $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $insert = $driver === 'mysql'
                    ? 'INSERT IGNORE INTO login_attempts
                        (bucket_hash, failed_attempts, window_started_at, updated_at)
                       VALUES (:bucket_hash, 0, :window_started_at, :updated_at)'
                    : 'INSERT OR IGNORE INTO login_attempts
                        (bucket_hash, failed_attempts, window_started_at, updated_at)
                       VALUES (:bucket_hash, 0, :window_started_at, :updated_at)';
                $initialize = $this->pdo->prepare($insert);
                $initialize->execute([
                    'bucket_hash' => $bucketHash,
                    'window_started_at' => $nowText,
                    'updated_at' => $nowText
                ]);
                $sql = 'SELECT failed_attempts, window_started_at FROM login_attempts WHERE bucket_hash = :bucket_hash';
                if ($driver === 'mysql') {
                    $sql .= ' FOR UPDATE';
                }
                $select = $this->pdo->prepare($sql);
                $select->execute(['bucket_hash' => $bucketHash]);
                $row = $select->fetch();
                $windowStart = $row ? strtotime((string) $row['window_started_at']) : 0;
                if ($now - $windowStart > $windowSeconds) {
                    $attempts = 1;
                    $this->writeBucket($bucketHash, $attempts, $now, null);
                } else {
                    $attempts = (int) $row['failed_attempts'] + 1;
                    $lockedUntil = $attempts >= $threshold ? $now + $lockSeconds : null;
                    $this->writeBucket($bucketHash, $attempts, $windowStart, $lockedUntil);
                }
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        }
    }

    public function clear(array $bucketHashes): void
    {
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE bucket_hash = :bucket_hash');
        foreach ($bucketHashes as $bucketHash) {
            $statement->execute(['bucket_hash' => $bucketHash]);
        }
    }

    private function writeBucket(string $hash, int $attempts, int $windowStart, ?int $lockedUntil): void
    {
        $parameters = [
            'bucket_hash' => $hash,
            'attempts' => $attempts,
            'window_started_at' => gmdate('Y-m-d H:i:s', $windowStart),
            'locked_until' => $lockedUntil === null ? null : gmdate('Y-m-d H:i:s', $lockedUntil),
            'updated_at' => gmdate('Y-m-d H:i:s')
        ];

        $statement = $this->pdo->prepare(
            'UPDATE login_attempts
             SET failed_attempts = :attempts, window_started_at = :window_started_at,
                 locked_until = :locked_until, updated_at = :updated_at
             WHERE bucket_hash = :bucket_hash'
        );
        $statement->execute($parameters);
    }
}
