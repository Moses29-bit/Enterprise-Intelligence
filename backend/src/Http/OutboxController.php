<?php
declare(strict_types=1);

namespace Vantiq\Http;

use Vantiq\Repository\OutboxRepository;
use Vantiq\Security\AuthMiddleware;
use Vantiq\Security\PermissionMiddleware;

final class OutboxController extends Controller
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        AuthMiddleware $authentication,
        PermissionMiddleware $permissions
    ) {
        parent::__construct($authentication, $permissions);
    }

    public function failures(): array
    {
        $user = $this->authenticatedUser();
        $organisationId = $this->organisationId($user);
        $this->requirePermission($user, 'users.manage');
        return ['events' => $this->outbox->failedEvents($organisationId)];
    }

    public function replay(array $params): array
    {
        $user = $this->authenticatedUser();
        $organisationId = $this->organisationId($user);
        $this->requirePermission($user, 'users.manage');
        if (!$this->outbox->requeueFailed($params['eventId'], $organisationId)) {
            throw new ApiException(404, 'failed_event_not_found', 'The failed event was not found in your organisation.');
        }
        return ['requeued' => true];
    }
}
