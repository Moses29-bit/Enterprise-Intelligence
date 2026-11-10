<?php
declare(strict_types=1);

namespace Vantiq\Http;

use Vantiq\Security\AuthMiddleware;
use Vantiq\Service\AccountService;

final class AccountController
{
    public function __construct(
        private readonly AuthMiddleware $middleware,
        private readonly AuthController $authController,
        private readonly AccountService $accounts
    ) {
    }

    public function updateStatus(Request $request, array $params): array
    {
        $actor = $this->middleware->requireUser();
        $this->authController->assertPermission((int) $actor['id'], 'users.manage');
        $input = $request->json();
        Validator::only($input, ['status', 'organisation_id']);
        $status = Validator::requiredString($input, 'status', 20);
        $requestedOrganisationId = null;
        if (array_key_exists('organisation_id', $input)) {
            if (!is_int($input['organisation_id']) || $input['organisation_id'] < 1) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'organisation_id' => 'Use a positive integer.'
                ]);
            }
            $requestedOrganisationId = $input['organisation_id'];
        }

        return ['account' => $this->accounts->updateStatus(
            $actor,
            (int) $params['id'],
            $status,
            $requestedOrganisationId
        )];
    }

    public function updateRoles(Request $request, array $params): array
    {
        $actor = $this->middleware->requireUser();
        $this->authController->assertPermission((int) $actor['id'], 'users.manage');
        $input = $request->json();
        Validator::only($input, ['role_ids']);
        $roleIds = $input['role_ids'] ?? null;
        if (!is_array($roleIds) || count($roleIds) > 10) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'role_ids' => 'Provide a list of no more than 10 role identifiers.'
            ]);
        }
        foreach ($roleIds as $roleId) {
            if (!is_int($roleId) || $roleId < 1) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'role_ids' => 'Every role identifier must be a positive integer.'
                ]);
            }
        }
        return ['assignment' => $this->accounts->updateRoles(
            $actor,
            (int) $params['id'],
            array_values(array_unique($roleIds))
        )];
    }
}
