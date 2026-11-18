<?php
declare(strict_types=1);

namespace Vantiq\Http;

use Vantiq\Security\AuthMiddleware;
use Vantiq\Security\PermissionMiddleware;

abstract class Controller
{
    public function __construct(
        protected readonly AuthMiddleware $middleware,
        protected readonly PermissionMiddleware $permissions
    ) {
    }

    protected function authenticatedUser(): array
    {
        return $this->middleware->requireUser();
    }

    protected function tenantUser(string $permission): array
    {
        $user = $this->authenticatedUser();
        $this->organisationId($user);
        $this->requirePermission($user, $permission);
        return $user;
    }

    protected function requirePermission(array $user, string $permission): void
    {
        $this->permissions->require($user, $permission);
    }

    protected function requireAnyPermission(array $user, array $permissions): void
    {
        $this->permissions->requireAny($user, $permissions);
    }

    protected function organisationId(array $user): int
    {
        $organisationId = filter_var($user['organisation_id'] ?? null, FILTER_VALIDATE_INT);
        if ($organisationId === false || $organisationId < 1) {
            throw new ApiException(403, 'tenant_required', 'This operation requires an organisation account.');
        }

        return $organisationId;
    }
}
