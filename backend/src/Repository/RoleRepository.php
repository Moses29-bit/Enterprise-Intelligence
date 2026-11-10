<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;

final class RoleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createOrganisationRoles(int $organisationId): int
    {
        $roles = [
            'Organisation Owner' => ['users.manage', 'finance.read', 'finance.write', 'operations.read', 'operations.write', 'connect.manage', 'decisions.manage', 'risks.read', 'risks.manage', 'reports.read'],
            'Executive / Director' => ['finance.read', 'operations.read', 'decisions.manage', 'risks.read', 'risks.manage', 'reports.read'],
            'Department Manager' => ['operations.read', 'risks.read', 'reports.read'],
            'Financial Analyst' => ['finance.read', 'finance.write', 'risks.read', 'reports.read'],
            'Operations Manager' => ['operations.read', 'operations.write', 'connect.manage', 'risks.read', 'risks.manage', 'reports.read']
        ];

        $roleInsert = $this->pdo->prepare(
            'INSERT INTO roles (organisation_id, name, description, is_system, created_at)
             VALUES (:organisation_id, :name, :description, 0, CURRENT_TIMESTAMP)'
        );
        $rolePermission = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT :role_id, id, CURRENT_TIMESTAMP FROM permissions WHERE code = :code'
        );

        $ownerRoleId = 0;
        foreach ($roles as $name => $permissions) {
            $roleInsert->execute([
                'organisation_id' => $organisationId,
                'name' => $name,
                'description' => $name . ' role'
            ]);
            $roleId = (int) $this->pdo->lastInsertId();
            if ($name === 'Organisation Owner') {
                $ownerRoleId = $roleId;
            }

            foreach ($permissions as $permission) {
                $rolePermission->execute(['role_id' => $roleId, 'code' => $permission]);
            }
        }

        return $ownerRoleId;
    }

    public function assign(int $userId, int $roleId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id, created_at)
             SELECT u.id, r.id, CURRENT_TIMESTAMP
             FROM users u
             JOIN roles r ON r.id = :role_id AND r.organisation_id = u.organisation_id AND r.is_system = 0
             WHERE u.id = :user_id AND u.organisation_id IS NOT NULL'
        );
        $statement->execute(['user_id' => $userId, 'role_id' => $roleId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The role could not be assigned within the user organisation.');
        }
    }

    public function replaceOrganisationRoles(int $userId, int $organisationId, array $roleIds): void
    {
        $userCheck = $this->pdo->prepare(
            'SELECT 1 FROM users WHERE id = :user_id AND organisation_id = :organisation_id LIMIT 1'
        );
        $userCheck->execute(['user_id' => $userId, 'organisation_id' => $organisationId]);
        if ($userCheck->fetchColumn() === false) {
            throw new \Vantiq\Http\ApiException(404, 'account_not_found', 'The account was not found in your organisation.');
        }

        $roleCheck = $this->pdo->prepare(
            'SELECT id FROM roles WHERE id = :role_id AND organisation_id = :organisation_id AND is_system = 0 LIMIT 1'
        );
        foreach ($roleIds as $roleId) {
            $roleCheck->execute(['role_id' => $roleId, 'organisation_id' => $organisationId]);
            if ($roleCheck->fetchColumn() === false) {
                throw new \Vantiq\Http\ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'role_ids' => 'Every selected role must belong to your organisation.'
                ]);
            }
        }

        $deleteSql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'DELETE FROM user_roles
               WHERE user_id = :user_id
                 AND role_id IN (SELECT id FROM roles WHERE organisation_id = :organisation_id AND is_system = 0)'
            : 'DELETE ur FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
               WHERE ur.user_id = :user_id AND r.organisation_id = :organisation_id AND r.is_system = 0';
        $delete = $this->pdo->prepare($deleteSql);
        $delete->execute(['user_id' => $userId, 'organisation_id' => $organisationId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id, created_at)
             VALUES (:user_id, :role_id, CURRENT_TIMESTAMP)'
        );
        foreach (array_unique($roleIds) as $roleId) {
            $insert->execute(['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    public function organisationRoles(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, description FROM roles
             WHERE organisation_id = :organisation_id AND is_system = 0
             ORDER BY name ASC'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return $statement->fetchAll();
    }

    public function userHasOrganisationRole(int $userId, int $organisationId, int $roleId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             JOIN users u ON u.id = ur.user_id
             WHERE u.id = :user_id AND u.organisation_id = :organisation_id
               AND r.id = :role_id AND r.organisation_id = :role_organisation_id AND r.is_system = 0
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'organisation_id' => $organisationId,
            'role_id' => $roleId,
            'role_organisation_id' => $organisationId,
        ]);
        return (bool) $statement->fetchColumn();
    }

    public function assignPlatformAdmin(int $userId): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO user_roles (user_id, role_id, created_at)
             SELECT :user_id, id, CURRENT_TIMESTAMP FROM roles
             WHERE organisation_id IS NULL AND name = :name AND is_system = 1"
        );
        $statement->execute(['user_id' => $userId, 'name' => 'Platform Super Administrator']);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The platform administrator role has not been seeded.');
        }
    }

    public function forUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.name
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             JOIN users u ON u.id = ur.user_id
             WHERE ur.user_id = :user_id
               AND (r.organisation_id = u.organisation_id OR (r.organisation_id IS NULL AND u.organisation_id IS NULL))
             ORDER BY r.name'
        );
        $statement->execute(['user_id' => $userId]);
        return array_column($statement->fetchAll(), 'name');
    }

    public function hasPermission(int $userId, string $permissionCode): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             JOIN users u ON u.id = ur.user_id
             WHERE ur.user_id = :user_id
               AND p.code = :permission
               AND (r.organisation_id = u.organisation_id OR (r.organisation_id IS NULL AND u.organisation_id IS NULL))
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'permission' => $permissionCode]);
        return (bool) $statement->fetchColumn();
    }

    public function permissionsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT p.code
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             JOIN users u ON u.id = ur.user_id
             WHERE ur.user_id = :user_id
               AND (r.organisation_id = u.organisation_id OR (r.organisation_id IS NULL AND u.organisation_id IS NULL))
             ORDER BY p.code'
        );
        $statement->execute(['user_id' => $userId]);
        return array_column($statement->fetchAll(), 'code');
    }
}
