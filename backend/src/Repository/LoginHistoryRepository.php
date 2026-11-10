<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;

final class LoginHistoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(?int $organisationId, ?int $userId, string $email, string $status, string $ipHash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_history (organisation_id, user_id, email, status, ip_hash, created_at)
             VALUES (:organisation_id, :user_id, :email, :status, :ip_hash, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'user_id' => $userId,
            'email' => $email,
            'status' => $status,
            'ip_hash' => $ipHash
        ]);
    }
}
