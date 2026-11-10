<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, string $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, CURRENT_TIMESTAMP)'
        );
        $statement->execute(['user_id' => $userId, 'token_hash' => $tokenHash, 'expires_at' => $expiresAt]);
    }

    public function consume(string $tokenHash, string $now): ?int
    {
        $sql = 'SELECT id, user_id FROM password_reset_tokens
                WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $select = $this->pdo->prepare($sql);
        $select->execute(['token_hash' => $tokenHash, 'now' => $now]);
        $row = $select->fetch();
        if (!$row) {
            return null;
        }

        $update = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at = :used_at
             WHERE id = :id AND used_at IS NULL AND expires_at > :expires_at'
        );
        $update->execute(['id' => $row['id'], 'used_at' => $now, 'expires_at' => $now]);
        return $update->rowCount() === 1 ? (int) $row['user_id'] : null;
    }

    public function invalidateForUser(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND used_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
    }
}
