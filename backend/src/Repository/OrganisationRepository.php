<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;

final class OrganisationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $name, string $slug): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO organisations (name, slug, status, created_at, updated_at)
             VALUES (:name, :slug, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $statement->execute(['name' => $name, 'slug' => $slug]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, slug, status FROM organisations WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function activeOrganisations(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, name FROM organisations WHERE status = 'active' ORDER BY name ASC"
        );
        return $statement->fetchAll();
    }

    public function slugExists(string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM organisations WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);
        return (bool) $statement->fetchColumn();
    }
}
