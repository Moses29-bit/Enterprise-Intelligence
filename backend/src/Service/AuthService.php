<?php
declare(strict_types=1);

namespace Vantiq\Service;

use PDO;
use PDOException;
use Vantiq\Config\Config;
use Vantiq\Http\ApiException;
use Vantiq\Model\Organisation;
use Vantiq\Model\User;
use Vantiq\Repository\AuditRepository;
use Vantiq\Repository\LoginAttemptRepository;
use Vantiq\Repository\LoginHistoryRepository;
use Vantiq\Repository\OrganisationRepository;
use Vantiq\Repository\PasswordResetRepository;
use Vantiq\Repository\RoleRepository;
use Vantiq\Repository\UserRepository;
use Vantiq\Security\SessionManager;

final class AuthService
{
    private const FAILURE_THRESHOLD = 5;
    private const FAILURE_WINDOW_SECONDS = 900;
    private const LOCK_SECONDS = 900;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly SessionManager $session,
        private readonly UserRepository $users,
        private readonly OrganisationRepository $organisations,
        private readonly RoleRepository $roles,
        private readonly AuditRepository $audit,
        private readonly LoginAttemptRepository $attempts,
        private readonly LoginHistoryRepository $loginHistory,
        private readonly PasswordResetRepository $resetTokens,
        private readonly PasswordResetDelivery $resetDelivery
    ) {
    }

    public function register(string $organisationName, string $name, string $email, string $password, string $ip): array
    {
        if ($this->users->findByEmail($email) !== null) {
            throw new ApiException(409, 'email_unavailable', 'An account already exists for that email address.');
        }

        $slug = $this->createSlug($organisationName);
        $this->pdo->beginTransaction();
        try {
            $organisationId = $this->organisations->create($organisationName, $slug);
            $ownerRoleId = $this->roles->createOrganisationRoles($organisationId);
            $userId = $this->users->create(
                $organisationId,
                $email,
                $name,
                password_hash($password, PASSWORD_DEFAULT)
            );
            $this->roles->assign($userId, $ownerRoleId);
            $this->audit->record($organisationId, $userId, 'organisation.registered', 'organisation');
            $this->loginHistory->record($organisationId, $userId, $email, 'success', $this->ipHash($ip));
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                throw new ApiException(409, 'registration_conflict', 'The organisation or email address is already registered.');
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $user = $this->users->findById($userId);
        $this->startSession($user);
        return ['user' => $this->publicUser($userId), 'csrfToken' => $this->session->csrfToken()];
    }

    public function login(string $email, string $password, string $ip): array
    {
        $buckets = $this->throttleBuckets($email, $ip);
        if ($this->attempts->isLocked($buckets, time())) {
            throw new ApiException(429, 'login_throttled', 'Sign-in is temporarily unavailable. Try again later.');
        }

        $user = $this->users->findByEmail($email);
        $organisationActive = $user !== null && ($user['organisation_id'] === null
            || ($this->organisations->findById((int) $user['organisation_id'])['status'] ?? null) === 'active');
        $valid = $user !== null
            && $organisationActive
            && $user['status'] === 'active'
            && password_verify($password, (string) $user['password_hash']);

        if (!$valid) {
            $this->attempts->fail($buckets, time(), self::FAILURE_WINDOW_SECONDS, self::FAILURE_THRESHOLD, self::LOCK_SECONDS);
            $this->loginHistory->record(
                $user === null || $user['organisation_id'] === null ? null : (int) $user['organisation_id'],
                $user === null ? null : (int) $user['id'],
                $email,
                'failed',
                $this->ipHash($ip)
            );
            if ($user !== null) {
                $this->audit->record((int) $user['organisation_id'], (int) $user['id'], 'auth.login_failed', 'user');
            }
            throw new ApiException(401, 'invalid_credentials', 'The email or password is incorrect.');
        }

        $this->attempts->clear([$buckets[0], $buckets[1]]);
        $this->pdo->beginTransaction();
        try {
            $this->users->touchLogin((int) $user['id']);
            $this->audit->record(
                $user['organisation_id'] === null ? null : (int) $user['organisation_id'],
                (int) $user['id'],
                'auth.login_succeeded',
                'user'
            );
            $this->loginHistory->record(
                $user['organisation_id'] === null ? null : (int) $user['organisation_id'],
                (int) $user['id'],
                $email,
                'success',
                $this->ipHash($ip)
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->startSession($user);
        return [
            'user' => $this->publicUser((int) $user['id']),
            'csrfToken' => $this->session->csrfToken()
        ];
    }

    public function logout(?array $user): void
    {
        try {
            if ($user !== null) {
                $organisationId = $user['organisation_id'] === null ? null : (int) $user['organisation_id'];
                $this->audit->record($organisationId, (int) $user['id'], 'auth.logout', 'user');
            }
        } finally {
            $this->session->destroy();
        }
    }

    public function currentUser(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new ApiException(401, 'unauthenticated', 'Authentication is required.');
        }

        return $this->publicUser($userId);
    }

    public function changePassword(array $user, string $currentPassword, string $newPassword): void
    {
        $stored = $this->users->findByEmail((string) $user['email']);
        if ($stored === null || !password_verify($currentPassword, (string) $stored['password_hash'])) {
            throw new ApiException(422, 'current_password_invalid', 'The current password is incorrect.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($this->users->changePassword((int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT)) !== 1) {
                throw new ApiException(409, 'account_unavailable', 'The account could not be updated.');
            }
            $this->resetTokens->invalidateForUser((int) $user['id']);
            $this->audit->record(
                $user['organisation_id'] === null ? null : (int) $user['organisation_id'],
                (int) $user['id'],
                'auth.password_changed',
                'user'
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $fresh = $this->users->findById((int) $user['id']);
        $_SESSION['auth_version'] = (int) $fresh['session_version'];
        $_SESSION['issued_at'] = time();
        $_SESSION['last_activity'] = time();
        $this->session->rotate();
    }

    public function requestPasswordReset(string $email): void
    {
        if (!$this->resetDelivery->isConfigured()) {
            throw new ApiException(503, 'mail_unavailable', 'Password reset delivery is not configured.');
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || $user['status'] !== 'active') {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);
        $this->resetTokens->invalidateForUser((int) $user['id']);
        $this->resetTokens->create((int) $user['id'], hash('sha256', $token), $expiresAt);

        $resetUrl = rtrim((string) $this->config->get('reset_url'), '?')
            . '?' . http_build_query(['token' => $token]);
        try {
            $this->resetDelivery->send((string) $user['email'], $resetUrl);
        } catch (\Throwable $exception) {
            $this->resetTokens->invalidateForUser((int) $user['id']);
            throw $exception;
        }
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new ApiException(422, 'reset_token_invalid', 'The password reset link is invalid or expired.');
        }

        $this->pdo->beginTransaction();
        try {
            $userId = $this->resetTokens->consume(hash('sha256', $token), gmdate('Y-m-d H:i:s'));
            if ($userId === null || $this->users->changePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT)) !== 1) {
                $this->pdo->rollBack();
                throw new ApiException(422, 'reset_token_invalid', 'The password reset link is invalid or expired.');
            }

            $this->resetTokens->invalidateForUser($userId);
            $user = $this->users->findById($userId);
            $this->audit->record(
                $user['organisation_id'] === null ? null : (int) $user['organisation_id'],
                $userId,
                'auth.password_reset',
                'user'
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->session->destroy();
        $this->session->start();
    }

    public function throttleBuckets(string $email, string $ip): array
    {
        $key = (string) $this->config->get('app_key');
        return [
            hash_hmac('sha256', 'email-ip|' . mb_strtolower($email) . '|' . $ip, $key),
            hash_hmac('sha256', 'email|' . mb_strtolower($email), $key),
            hash_hmac('sha256', 'ip|' . $ip, $key)
        ];
    }

    private function startSession(?array $user): void
    {
        $this->session->rotate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['organisation_id'] = $user['organisation_id'] === null ? null : (int) $user['organisation_id'];
        $_SESSION['auth_version'] = (int) $user['session_version'];
        $_SESSION['issued_at'] = time();
        $_SESSION['last_activity'] = time();
    }

    private function publicUser(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new ApiException(401, 'unauthenticated', 'Authentication is required.');
        }

        $organisationRow = $user['organisation_id'] === null
            ? null
            : $this->organisations->findById((int) $user['organisation_id']);
        $organisation = $organisationRow === null ? null : Organisation::fromRow($organisationRow);
        return User::fromRow($user)->toPublicArray(
            $organisation,
            $this->roles->forUser($userId),
            $this->roles->permissionsForUser($userId)
        );
    }

    private function createSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($name)), '-');
        $base = substr($base === '' ? 'organisation' : $base, 0, 100);
        do {
            $slug = $base . '-' . bin2hex(random_bytes(4));
        } while ($this->organisations->slugExists($slug));
        return $slug;
    }

    private function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) $this->config->get('app_key'));
    }
}
