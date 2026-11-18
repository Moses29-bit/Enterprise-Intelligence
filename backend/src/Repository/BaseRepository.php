<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use LogicException;
use PDO;
use PDOStatement;

abstract class BaseRepository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    protected function execute(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    protected function executeTenantScoped(string $sql, int $organisationId, array $parameters = []): PDOStatement
    {
        if (!preg_match('/\b(?:WHERE|AND|ON)\s+(?:[a-zA-Z0-9_]+\.)?organisation_id\s*=\s*:organisation_id\b/i', $sql)) {
            throw new LogicException('Tenant-scoped queries must bind an organisation_id predicate.');
        }

        $parameters['organisation_id'] = $organisationId;
        return $this->execute($sql, $parameters);
    }
}
