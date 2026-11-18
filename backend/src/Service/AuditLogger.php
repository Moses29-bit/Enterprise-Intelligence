<?php
declare(strict_types=1);

namespace Vantiq\Service;

use Vantiq\Repository\AuditRepository;

final class AuditLogger
{
    public function __construct(private readonly AuditRepository $repository)
    {
    }

    public function record(
        ?int $organisationId,
        ?int $userId,
        string $action,
        string $entity,
        ?array $before = null,
        ?array $after = null,
        array $context = []
    ): void {
        $this->repository->record($organisationId, $userId, $action, $entity, [
            'before' => $before,
            'after' => $after,
            'context' => $context,
        ]);
    }
}
