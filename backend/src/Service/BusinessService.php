<?php
declare(strict_types=1);

namespace Vantiq\Service;

use Vantiq\Repository\BusinessRepository;

final class BusinessService
{
    public function __construct(private readonly BusinessRepository $repository)
    {
    }

    public function financeSummary(int $organisationId): array
    {
        return $this->repository->financeSummary($organisationId);
    }

    public function operationsSummary(int $organisationId): array
    {
        return $this->repository->operationsSummary($organisationId);
    }

    public function reportsSummary(int $organisationId): array
    {
        return $this->repository->reportsSummary($organisationId);
    }

    public function forecastSummary(int $organisationId): array
    {
        return $this->repository->forecastSummary($organisationId);
    }

    public function riskAlerts(int $organisationId): array
    {
        return $this->repository->riskAlerts($organisationId);
    }

    public function decisionScenarios(int $organisationId): array
    {
        return $this->repository->decisionScenarios($organisationId);
    }

    public function importsHistory(int $organisationId): array
    {
        return $this->repository->importsHistory($organisationId);
    }

    public function adminOverview(int $organisationId): array
    {
        return $this->repository->adminOverview($organisationId);
    }

    public function adminUsers(int $organisationId): array
    {
        return $this->repository->adminUsers($organisationId);
    }

    public function adminBranches(int $organisationId): array
    {
        return $this->repository->adminBranches($organisationId);
    }

    public function adminDepartments(int $organisationId): array
    {
        return $this->repository->adminDepartments($organisationId);
    }

    public function adminRoles(int $organisationId): array
    {
        return $this->repository->adminRoles($organisationId);
    }

    public function createBranch(int $organisationId, int $userId, string $name, string $code): array
    {
        return $this->repository->createBranch($organisationId, $userId, $name, $code);
    }

    public function createDepartment(int $organisationId, int $userId, string $name, string $code, ?int $branchId): array
    {
        return $this->repository->createDepartment($organisationId, $userId, $name, $code, $branchId);
    }

    public function riskAlertStatusUpdate(int $organisationId, int $alertId, string $status, ?int $reviewerUserId, ?string $resolutionNotes): array
    {
        return $this->repository->riskAlertStatusUpdate($organisationId, $alertId, $status, $reviewerUserId, $resolutionNotes);
    }

    public function createDecisionScenario(int $organisationId, int $userId, array $input): array
    {
        return $this->repository->createDecisionScenario($organisationId, $userId, $input);
    }

    public function decisionScenarioDetail(int $organisationId, int $scenarioId): array
    {
        return $this->repository->decisionScenarioDetail($organisationId, $scenarioId);
    }

    public function importPreview(int $organisationId, int $userId, string $fileName, string $csvData): array
    {
        return $this->repository->importPreview($organisationId, $userId, $fileName, $csvData);
    }
}
