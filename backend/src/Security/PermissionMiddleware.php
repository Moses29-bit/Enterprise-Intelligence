<?php
declare(strict_types=1);

namespace Vantiq\Security;

use Vantiq\Http\ApiException;
use Vantiq\Repository\RoleRepository;

final class PermissionMiddleware
{
    public function __construct(private readonly RoleRepository $roles)
    {
    }

    public function require(array $user, string $permission): void
    {
        $userId = $this->userId($user);

        if ($this->roles->hasPermission($userId, $permission)
            || $this->roles->hasPermission($userId, 'platform.users.manage')) {
            return;
        }

        throw new ApiException(403, 'forbidden', 'You do not have permission to perform this action.');
    }

    public function requireAny(array $user, array $permissions): void
    {
        $userId = $this->userId($user);
        foreach ($permissions as $permission) {
            if ($this->roles->hasPermission($userId, $permission)
                || $this->roles->hasPermission($userId, 'platform.users.manage')) {
                return;
            }
        }

        throw new ApiException(403, 'forbidden', 'You do not have permission to perform this action.');
    }

    private function userId(array $user): int
    {
        $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            throw new ApiException(401, 'unauthenticated', 'Authentication is required.');
        }

        return $userId;
    }
}
