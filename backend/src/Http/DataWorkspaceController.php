<?php
declare(strict_types=1);

namespace Vantiq\Http;

use Vantiq\Security\AuthMiddleware;
use Vantiq\Security\PermissionMiddleware;
use Vantiq\Repository\OrganisationRepository;
use Vantiq\Service\DataWorkspaceService;

final class DataWorkspaceController extends Controller
{
    public function __construct(
        private readonly DataWorkspaceService $service,
        AuthMiddleware $middleware,
        PermissionMiddleware $permissions,
        private readonly OrganisationRepository $organisations
    ) {
        parent::__construct($middleware, $permissions);
    }

    public function documents(Request $request): array
    {
        $user = $this->tenantUserAny(['documents.read', 'documents.upload']);
        return $this->service->documents($this->organisationId($user), $request->query);
    }

    public function documentVersions(array $params): array
    {
        $user = $this->tenantUserAny(['documents.read', 'documents.upload']);
        return $this->service->documentVersions($this->organisationId($user), $params['group']);
    }

    public function documentExtraction(array $params): array
    {
        $user = $this->tenantUserAny(['documents.read', 'documents.upload']);
        return $this->service->documentExtraction($this->organisationId($user), (int) $params['id']);
    }

    public function uploadDocument(Request $request): array
    {
        $user = $this->tenantUser('documents.upload');
        return $this->service->uploadDocument(
            $this->organisationId($user),
            (int) $user['id'],
            $request->files['file'] ?? [],
            $request->form
        );
    }

    public function reviewDocument(array $params): array
    {
        $user = $this->tenantUser('documents.upload');
        return $this->service->reviewDocument($this->organisationId($user), (int) $user['id'], (int) $params['id']);
    }

    public function importHistory(): array
    {
        $user = $this->tenantUserAny(['data.import', 'connect.manage', 'finance.write']);
        return $this->service->importHistory($this->organisationId($user));
    }

    public function uploadImport(Request $request): array
    {
        $user = $this->tenantUserAny(['data.import', 'connect.manage', 'finance.write']);
        return $this->service->uploadImport(
            $this->organisationId($user),
            (int) $user['id'],
            $request->files['file'] ?? [],
            $request->form
        );
    }

    public function validateImport(Request $request, array $params): array
    {
        $user = $this->tenantUserAny(['data.import', 'connect.manage', 'finance.write']);
        return $this->service->validateImport(
            $this->organisationId($user),
            (int) $params['id'],
            $request->json()
        );
    }

    public function commitImport(array $params): array
    {
        $user = $this->tenantUserAny(['data.import', 'connect.manage', 'finance.write']);
        return $this->service->commitImport($this->organisationId($user), (int) $params['id']);
    }

    public function rollbackImport(array $params): array
    {
        $user = $this->tenantUserAny(['data.import', 'connect.manage', 'finance.write']);
        return $this->service->rollbackImport($this->organisationId($user), (int) $params['id']);
    }

    public function template(array $params): array
    {
        $user = $this->tenantUserAny(['data.import', 'connect.manage', 'finance.write']);
        $this->organisationId($user);
        $this->service->templates($params['type']);
        return [];
    }

    public function integrationOrganisations(): array
    {
        $user = $this->authenticatedUser();
        $this->requirePermission($user, 'platform.users.manage');
        return ['organisations' => $this->organisations->activeOrganisations()];
    }

    public function integrations(Request $request): array
    {
        $user = $this->authenticatedUser();
        $organisationId = $this->integrationOrganisation($user, $request->query['organisation_id'] ?? null);
        return $this->service->integrations($organisationId);
    }

    public function createIntegration(Request $request): array
    {
        $user = $this->authenticatedUser();
        $payload = $request->json();
        $organisationId = $this->integrationOrganisation($user, $payload['organisation_id'] ?? null);
        unset($payload['organisation_id']);
        return $this->service->createIntegration(
            $organisationId,
            (int) $user['id'],
            $payload
        );
    }

    public function testConnection(Request $request): array
    {
        $user = $this->authenticatedUser();
        $payload = $request->json();
        $this->integrationOrganisation($user, $payload['organisation_id'] ?? null);
        unset($payload['organisation_id']);
        return $this->service->testConnection($payload);
    }

    public function activateIntegration(Request $request, array $params): array
    {
        $user = $this->authenticatedUser();
        $organisationId = $this->integrationOrganisation($user, $request->query['organisation_id'] ?? null);
        return $this->service->activateIntegration($organisationId, (int) $params['id']);
    }

    public function syncIntegration(Request $request, array $params): array
    {
        $user = $this->authenticatedUser();
        $organisationId = $this->integrationOrganisation($user, $request->query['organisation_id'] ?? null);
        return $this->service->syncIntegration($organisationId, (int) $params['id']);
    }

    private function tenantUserAny(array $permissions): array
    {
        $user = $this->authenticatedUser();
        $this->organisationId($user);
        $this->requireAnyPermission($user, $permissions);
        return $user;
    }

    private function integrationOrganisation(array $user, mixed $requestedOrganisationId): int
    {
        if (($user['organisation_id'] ?? null) !== null) {
            $organisationId = $this->organisationId($user);
            $this->requirePermission($user, 'integrations.manage');
            if ($requestedOrganisationId !== null
                && filter_var($requestedOrganisationId, FILTER_VALIDATE_INT) !== $organisationId) {
                throw new ApiException(403, 'forbidden', 'You can manage integrations only for your organisation.');
            }
            return $organisationId;
        }

        $this->requirePermission($user, 'platform.users.manage');
        $organisationId = filter_var($requestedOrganisationId, FILTER_VALIDATE_INT);
        if ($organisationId === false || $organisationId < 1) {
            throw new ApiException(422, 'validation_failed', 'Select an organisation to manage its integrations.');
        }
        $organisation = $this->organisations->findById($organisationId);
        if ($organisation === null || $organisation['status'] !== 'active') {
            throw new ApiException(404, 'organisation_not_found', 'The selected organisation is unavailable.');
        }
        return $organisationId;
    }
}
