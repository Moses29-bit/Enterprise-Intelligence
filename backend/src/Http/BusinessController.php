<?php
declare(strict_types=1);

namespace Vantiq\Http;

use Vantiq\Repository\RoleRepository;
use Vantiq\Security\AuthMiddleware;
use Vantiq\Service\BusinessService;

final class BusinessController
{
    public function __construct(
        private readonly BusinessService $service,
        private readonly AuthMiddleware $middleware,
        private readonly RoleRepository $roles
    ) {
    }

    public function financeSummary(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'finance.read');
        return $this->service->financeSummary((int) $user['organisation_id']);
    }

    public function operationsSummary(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'operations.read');
        return $this->service->operationsSummary((int) $user['organisation_id']);
    }

    public function reportsSummary(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'reports.read');
        return $this->service->reportsSummary((int) $user['organisation_id']);
    }

    public function forecastSummary(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'operations.read');
        return $this->service->forecastSummary((int) $user['organisation_id']);
    }

    public function riskAlerts(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'risks.read');
        return $this->service->riskAlerts((int) $user['organisation_id']);
    }

    public function decisionScenarios(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'decisions.manage');
        return $this->service->decisionScenarios((int) $user['organisation_id']);
    }

    public function importsHistory(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'connect.manage');
        return $this->service->importsHistory((int) $user['organisation_id']);
    }

    public function adminOverview(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'users.manage');
        return $this->service->adminOverview($this->organisationId($user));
    }

    public function adminUsers(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'users.manage');
        return $this->service->adminUsers($this->organisationId($user));
    }

    public function adminBranches(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'users.manage');
        return $this->service->adminBranches($this->organisationId($user));
    }

    public function adminDepartments(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'users.manage');
        return $this->service->adminDepartments($this->organisationId($user));
    }

    public function adminRoles(): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'users.manage');
        return $this->service->adminRoles($this->organisationId($user));
    }

    public function createBranch(Request $request): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'users.manage');
        $input = $request->json();
        Validator::only($input, ['name', 'code']);
        $name = Validator::requiredString($input, 'name', 160);
        $code = $this->code($input, 'code');
        return $this->service->createBranch(
            $this->organisationId($user),
            (int) $user['id'],
            $name,
            $code
        );
    }

    public function createDepartment(Request $request): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'users.manage');
        $input = $request->json();
        Validator::only($input, ['name', 'code', 'branch_id']);
        $name = Validator::requiredString($input, 'name', 120);
        $code = $this->code($input, 'code');
        $branchId = null;
        if (array_key_exists('branch_id', $input) && $input['branch_id'] !== null) {
            if (!is_int($input['branch_id']) || $input['branch_id'] < 1) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'branch_id' => 'Use a positive integer.'
                ]);
            }
            $branchId = $input['branch_id'];
        }
        return $this->service->createDepartment(
            $this->organisationId($user),
            (int) $user['id'],
            $name,
            $code,
            $branchId
        );
    }

    public function riskAlertStatusUpdate(Request $request, array $params): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'risks.manage');
        $input = $request->json();
        Validator::only($input, ['status', 'reviewer_user_id', 'resolution_notes']);
        $status = Validator::requiredString($input, 'status', 20);
        if (!in_array($status, ['open', 'assigned', 'reviewing', 'resolved', 'dismissed'], true)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'status' => 'Choose an allowed alert status.'
            ]);
        }
        $reviewerUserId = null;
        if (array_key_exists('reviewer_user_id', $input) && $input['reviewer_user_id'] !== null) {
            if (!is_int($input['reviewer_user_id']) || $input['reviewer_user_id'] < 1) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'reviewer_user_id' => 'Use a positive integer.'
                ]);
            }
            $reviewerUserId = $input['reviewer_user_id'];
        }
        if (array_key_exists('resolution_notes', $input)
            && $input['resolution_notes'] !== null
            && (!is_string($input['resolution_notes']) || mb_strlen(trim($input['resolution_notes'])) > 4000)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'resolution_notes' => 'Use no more than 4000 characters.'
            ]);
        }
        $resolutionNotes = is_string($input['resolution_notes'] ?? null)
            ? trim($input['resolution_notes'])
            : null;

        return $this->service->riskAlertStatusUpdate(
            (int) $user['organisation_id'],
            (int) $params['id'],
            $status,
            $reviewerUserId,
            $resolutionNotes === '' ? null : $resolutionNotes
        );
    }

    public function createDecisionScenario(Request $request): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'decisions.manage');
        $input = $request->json();
        Validator::only($input, ['name', 'description', 'status', 'assumptions']);
        $status = $input['status'] ?? 'draft';
        if (!is_string($status) || !in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'status' => 'Choose draft, active, or archived.'
            ]);
        }
        $assumptions = $input['assumptions'] ?? [];
        if ($assumptions instanceof \stdClass) {
            $assumptions = get_object_vars($assumptions);
        }
        if (!is_array($assumptions)
            || array_diff(array_keys($assumptions), ['revenue_growth', 'price_increase', 'cost_reduction']) !== []) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'assumptions' => 'Only revenue_growth, price_increase, and cost_reduction assumptions are supported.'
            ]);
        }
        foreach ($assumptions as $key => $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                || (float) $value < 0 || (float) $value > 100) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'assumptions.' . $key => 'Enter a percentage from 0 to 100.'
                ]);
            }
        }
        $description = $input['description'] ?? '';
        if (!is_string($description) || mb_strlen(trim($description)) > 2000) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'description' => 'Use no more than 2000 characters.'
            ]);
        }
        $payload = [
            'name' => Validator::requiredString($input, 'name', 180),
            'description' => trim($description),
            'status' => $status,
            'assumptions' => $assumptions,
        ];

        return $this->service->createDecisionScenario((int) $user['organisation_id'], (int) $user['id'], $payload);
    }

    public function decisionScenarioDetail(array $params): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'decisions.manage');
        return $this->service->decisionScenarioDetail((int) $user['organisation_id'], (int) $params['id']);
    }

    public function importPreview(Request $request): array
    {
        $user = $this->middleware->requireUser();
        $this->requirePermission((int) $user['id'], 'connect.manage');
        $input = $request->json();
        Validator::only($input, ['file_name', 'csv']);
        $fileName = Validator::requiredString($input, 'file_name', 255);
        $csvData = is_string($input['csv'] ?? null) ? trim($input['csv']) : '';
        if ($csvData === '') {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'csv' => 'CSV content is required.'
            ]);
        }

        return $this->service->importPreview((int) $user['organisation_id'], (int) $user['id'], $fileName, $csvData);
    }

    private function requirePermission(int $userId, string $permission): void
    {
        if ($this->roles->hasPermission($userId, $permission) || $this->roles->hasPermission($userId, 'platform.users.manage')) {
            return;
        }

        throw new ApiException(403, 'forbidden', 'You do not have permission to access this module.');
    }

    private function organisationId(array $user): int
    {
        if ($user['organisation_id'] === null) {
            throw new ApiException(403, 'tenant_required', 'This operation requires an organisation account.');
        }
        return (int) $user['organisation_id'];
    }

    private function code(array $input, string $field): string
    {
        $code = Validator::requiredString($input, $field, 30);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $code)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'Use letters, numbers, dots, underscores, or hyphens.'
            ]);
        }
        return $code;
    }
}
