<?php
declare(strict_types=1);

namespace Vantiq\Service;

use PDO;
use Vantiq\Http\ApiException;
use Vantiq\Repository\AuditRepository;
use Vantiq\Repository\RoleRepository;
use Vantiq\Repository\UserRepository;

final class AccountService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly AuditRepository $audit
    ) {
    }

    public function updateStatus(array $actor, int $targetUserId, string $status, ?int $requestedOrganisationId): array
    {
        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            throw new ApiException(422, 'validation_failed', 'The account status is invalid.', [
                'status' => 'Choose active, inactive, or suspended.'
            ]);
        }

        if ((int) $actor['id'] === $targetUserId && $status !== 'active') {
            throw new ApiException(422, 'self_suspension_forbidden', 'You cannot deactivate your own account.');
        }

        $isPlatformAdmin = $this->roles->hasPermission((int) $actor['id'], 'platform.users.manage');
        $organisationId = $actor['organisation_id'] === null ? null : (int) $actor['organisation_id'];
        if ($isPlatformAdmin) {
            if ($requestedOrganisationId === null) {
                $target = $this->users->findById($targetUserId);
                $organisationId = $target === null || $target['organisation_id'] === null
                    ? null
                    : (int) $target['organisation_id'];
            } else {
                $organisationId = $requestedOrganisationId;
            }
        } elseif ($requestedOrganisationId !== null && $requestedOrganisationId !== $organisationId) {
            throw new ApiException(403, 'forbidden', 'You cannot manage accounts outside your organisation.');
        }

        $this->pdo->beginTransaction();
        try {
            $updated = $this->users->updateStatus($targetUserId, $organisationId, $status);
            if ($updated === null) {
                throw new ApiException(404, 'account_not_found', 'The account was not found in your organisation.');
            }

            $this->audit->record(
                $updated['organisation_id'] === null ? null : (int) $updated['organisation_id'],
                (int) $actor['id'],
                'account.status_changed',
                'user',
                ['target_user_id' => $targetUserId, 'status' => $status]
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'id' => (int) $updated['id'],
            'status' => $updated['status'],
            'organisation_id' => $updated['organisation_id'] === null ? null : (int) $updated['organisation_id']
        ];
    }

    public function updateRoles(array $actor, int $targetUserId, array $roleIds): array
    {
        $organisationId = $actor['organisation_id'] === null ? null : (int) $actor['organisation_id'];
        if ($organisationId === null) {
            throw new ApiException(403, 'tenant_required', 'Role administration requires an organisation account.');
        }
        if ((int) $actor['id'] === $targetUserId) {
            throw new ApiException(422, 'self_role_change_forbidden', 'You cannot change your own roles.');
        }

        $availableRoles = $this->roles->organisationRoles($organisationId);
        $ownerRoleId = null;
        foreach ($availableRoles as $role) {
            if ($role['name'] === 'Organisation Owner') {
                $ownerRoleId = (int) $role['id'];
                break;
            }
        }
        if ($ownerRoleId !== null && !in_array($ownerRoleId, $roleIds, true)) {
            $otherOwners = $this->pdo->prepare(
                'SELECT COUNT(DISTINCT u.id)
                 FROM users u
                 JOIN user_roles ur ON ur.user_id = u.id
                 JOIN roles r ON r.id = ur.role_id
                 WHERE u.organisation_id = :organisation_id AND u.id <> :target_user_id
                   AND r.organisation_id = :role_organisation_id AND r.name = :role_name'
            );
            $otherOwners->execute([
                'organisation_id' => $organisationId,
                'target_user_id' => $targetUserId,
                'role_organisation_id' => $organisationId,
                'role_name' => 'Organisation Owner',
            ]);
            if ((int) $otherOwners->fetchColumn() === 0) {
                if ($this->roles->userHasOrganisationRole($targetUserId, $organisationId, $ownerRoleId)) {
                    throw new ApiException(409, 'last_owner_required', 'At least one organisation owner must remain assigned.');
                }
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->roles->replaceOrganisationRoles($targetUserId, $organisationId, $roleIds);
            $this->audit->record($organisationId, (int) $actor['id'], 'account.roles_changed', 'user', [
                'target_user_id' => $targetUserId,
                'role_ids' => array_values(array_unique($roleIds)),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'user_id' => $targetUserId,
            'roles' => array_values(array_map(
                static fn (array $role): string => $role['name'],
                array_filter($availableRoles, static fn (array $role): bool => in_array((int) $role['id'], $roleIds, true))
            )),
        ];
    }
}
