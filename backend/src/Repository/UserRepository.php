<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $organisationId, string $email, string $name, string $passwordHash): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO users
                (organisation_id, email, password_hash, full_name, status, session_version, created_at, updated_at)
             VALUES (:organisation_id, :email, :password_hash, :full_name, 'active', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'email' => $email,
            'password_hash' => $passwordHash,
            'full_name' => $name
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createPlatformAdmin(string $email, string $name, string $passwordHash): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO users
                (organisation_id, email, password_hash, full_name, status, session_version, created_at, updated_at)
             VALUES (NULL, :email, :password_hash, :full_name, 'active', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $statement->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'full_name' => $name
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, organisation_id, email, full_name, status, session_version, last_login_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function touchLogin(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :status'
        );
        $statement->execute(['id' => $userId, 'status' => 'active']);
    }

    public function changePassword(int $userId, string $passwordHash): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET password_hash = :password_hash, session_version = session_version + 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :status'
        );
        $statement->execute(['password_hash' => $passwordHash, 'id' => $userId, 'status' => 'active']);
        return $statement->rowCount();
    }

    public function updateStatus(int $userId, ?int $organisationId, string $status): ?array
    {
        $sql = 'UPDATE users SET status = :status, session_version = session_version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';
        $parameters = ['status' => $status, 'id' => $userId];
        if ($organisationId === null) {
            $sql .= ' AND organisation_id IS NULL';
        } else {
            $sql .= ' AND organisation_id = :organisation_id';
            $parameters['organisation_id'] = $organisationId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        if ($statement->rowCount() === 0) {
            return null;
        }

        return $this->findById($userId);
    }
}
