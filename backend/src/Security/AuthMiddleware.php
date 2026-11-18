<?php
declare(strict_types=1);

namespace Vantiq\Security;

use Vantiq\Config\Config;
use Vantiq\Http\ApiException;
use Vantiq\Repository\OrganisationRepository;
use Vantiq\Repository\UserRepository;

final class AuthMiddleware
{
    public function __construct(
        private readonly Config $config,
        private readonly SessionManager $session,
        private readonly UserRepository $users,
        private readonly OrganisationRepository $organisations
    ) {
    }

    public function requireUser(): array
    {
        $userId = $this->session->userId();
        $now = time();
        if ($userId === null
            || !isset($_SESSION['issued_at'], $_SESSION['last_activity'], $_SESSION['auth_version'])
            || $now - (int) $_SESSION['last_activity'] > $this->config->get('session_idle_timeout')
            || $now - (int) $_SESSION['issued_at'] > $this->config->get('session_absolute_timeout')) {
            $this->session->destroy();
            throw new ApiException(401, 'unauthenticated', 'Authentication is required.');
        }

        $user = $this->users->findById($userId);
        if ($user === null || $user['status'] !== 'active'
            || (int) $user['session_version'] !== (int) $_SESSION['auth_version']) {
            $this->session->destroy();
            throw new ApiException(401, 'session_expired', 'Your session has expired. Sign in again.');
        }

        $_SESSION['organisation_id'] = $user['organisation_id'] === null
            ? null
            : (int) $user['organisation_id'];

        if ($user['organisation_id'] !== null) {
            $organisation = $this->organisations->findById((int) $user['organisation_id']);
            if ($organisation === null || $organisation['status'] !== 'active') {
                $this->session->destroy();
                throw new ApiException(401, 'session_expired', 'Your session has expired. Sign in again.');
            }
        }

        $_SESSION['last_activity'] = $now;
        return $user;
    }
}
