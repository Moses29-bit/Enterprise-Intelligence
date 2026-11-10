<?php
declare(strict_types=1);

namespace Vantiq\Http;

use Vantiq\Repository\RoleRepository;
use Vantiq\Security\AuthMiddleware;
use Vantiq\Security\SessionManager;
use Vantiq\Service\AuthService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuthMiddleware $middleware,
        private readonly RoleRepository $roles,
        private readonly SessionManager $session
    ) {
    }

    public function csrf(): array
    {
        return ['csrfToken' => $this->session->csrfToken()];
    }

    public function register(Request $request): array
    {
        $input = $request->json();
        Validator::only($input, ['organisation_name', 'name', 'email', 'password']);
        $organisation = Validator::requiredString($input, 'organisation_name', 180);
        $name = Validator::requiredString($input, 'name', 180);
        $email = Validator::email($input);
        $password = Validator::password($input);
        return $this->auth->register($organisation, $name, $email, $password, $request->ip);
    }

    public function login(Request $request): array
    {
        $input = $request->json();
        Validator::only($input, ['email', 'password']);
        $email = Validator::email($input);
        $password = $input['password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > Validator::PASSWORD_MAX_BYTES) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'password' => 'A password is required.'
            ]);
        }
        return $this->auth->login($email, $password, $request->ip);
    }

    public function logout(): array
    {
        $user = null;
        if ($this->session->userId() !== null) {
            try {
                $user = $this->middleware->requireUser();
            } catch (ApiException) {
                $user = null;
            }
        }
        $this->auth->logout($user);
        return ['loggedOut' => true];
    }

    public function me(): array
    {
        $user = $this->middleware->requireUser();
        return ['user' => $this->auth->currentUser((int) $user['id'])];
    }

    public function changePassword(Request $request): array
    {
        $user = $this->middleware->requireUser();
        $input = $request->json();
        Validator::only($input, ['current_password', 'new_password']);
        $currentPassword = $input['current_password'] ?? null;
        if (!is_string($currentPassword) || $currentPassword === '' || strlen($currentPassword) > Validator::PASSWORD_MAX_BYTES) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'current_password' => 'The current password is required.'
            ]);
        }

        $newPassword = Validator::password($input, 'new_password');
        $this->auth->changePassword($user, $currentPassword, $newPassword);
        return ['changed' => true, 'csrfToken' => $this->session->csrfToken()];
    }

    public function forgotPassword(Request $request): array
    {
        $input = $request->json();
        Validator::only($input, ['email']);
        $email = Validator::email($input);
        $this->auth->requestPasswordReset($email);
        return ['message' => 'If the account exists, password reset instructions have been sent.'];
    }

    public function resetPassword(Request $request): array
    {
        $input = $request->json();
        Validator::only($input, ['token', 'password']);
        $token = Validator::requiredString($input, 'token', 128);
        $password = Validator::password($input);
        $this->auth->resetPassword($token, $password);
        return ['reset' => true, 'csrfToken' => $this->session->csrfToken()];
    }

    public function assertPermission(int $userId, string $permission): void
    {
        if (!$this->roles->hasPermission($userId, $permission)
            && !$this->roles->hasPermission($userId, 'platform.users.manage')) {
            throw new ApiException(403, 'forbidden', 'You do not have permission to perform this action.');
        }
    }
}
