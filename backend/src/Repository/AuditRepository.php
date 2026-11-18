<?php
declare(strict_types=1);

namespace Vantiq\Repository;

final class AuditRepository extends BaseRepository
{
    public function record(?int $organisationId, ?int $userId, string $action, string $entity, array $details = []): void
    {
        $this->execute(
            'INSERT INTO audit_logs (organisation_id, user_id, action_type, entity_name, details, created_at)
             VALUES (:organisation_id, :user_id, :action_type, :entity_name, :details, CURRENT_TIMESTAMP)',
            [
                'organisation_id' => $organisationId,
                'user_id' => $userId,
                'action_type' => $action,
                'entity_name' => $entity,
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            ]
        );
    }
}
