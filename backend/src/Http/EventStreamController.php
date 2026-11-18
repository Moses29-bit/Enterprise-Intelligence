<?php
declare(strict_types=1);

namespace Vantiq\Http;

use Throwable;
use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\OutboxRepository;
use Vantiq\Repository\RoleRepository;
use Vantiq\Security\AuthMiddleware;

final class EventStreamController
{
    private const MODULE_PERMISSIONS = [
        'command' => ['finance.read', 'operations.read', 'reports.read', 'risks.read'],
        'overview' => ['finance.read', 'operations.read', 'reports.read', 'risks.read', 'users.manage'],
        'finance' => ['finance.read'],
        'operations' => ['operations.read'],
        'reports' => ['reports.read'],
        'forecast' => ['operations.read', 'reports.read'],
        'risk' => ['risks.read'],
        'connect' => ['connect.manage', 'documents.read', 'documents.upload'],
        'control' => ['users.manage', 'documents.read', 'documents.upload'],
    ];

    public function __construct(
        private readonly AuthMiddleware $authentication,
        private readonly RoleRepository $roles,
        private readonly OutboxRepository $outbox,
        private readonly Logger $logger
    ) {
    }

    public function stream(Request $request): void
    {
        try {
            $user = $this->authentication->requireUser();
            $organisationId = filter_var($user['organisation_id'] ?? null, FILTER_VALIDATE_INT);
            if ($organisationId === false || $organisationId < 1) {
                throw new ApiException(403, 'tenant_required', 'An organisation account is required for this event stream.');
            }

            $permissions = array_fill_keys($this->roles->permissionsForUser((int) $user['id']), true);
            if ($permissions === []) {
                throw new ApiException(403, 'forbidden', 'You do not have permission to receive business updates.');
            }

            $lastEventId = $request->header('last-event-id') ?? (string) ($request->query['after'] ?? '0');
            $cursor = ctype_digit($lastEventId) ? (int) $lastEventId : 0;
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $this->send((int) $organisationId, $cursor, $permissions);
        } catch (ApiException $exception) {
            http_response_code($exception->status);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            echo 'event: stream-error' . "\n";
            echo 'data: ' . json_encode(['code' => $exception->errorCode, 'message' => $exception->getMessage()]) . "\n\n";
            flush();
        } catch (Throwable $exception) {
            $referenceId = $this->logger->exception(
                'Business event stream failed',
                $exception,
                ['operation' => 'GET /events/stream', 'stage' => 'stream_setup']
            );
            http_response_code(503);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            echo 'event: stream-error' . "\n";
            echo 'data: ' . json_encode(['code' => 'stream_unavailable', 'reference_id' => $referenceId]) . "\n\n";
            flush();
        }
    }

    private function send(int $organisationId, int $cursor, array $permissions): void
    {
        set_time_limit(25);
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        $startedAt = time();
        $lastHeartbeat = $startedAt;

        while (time() - $startedAt < 20 && !connection_aborted()) {
            $notifications = $this->outbox->notificationsAfter($organisationId, $cursor);
            foreach ($notifications as $notification) {
                $cursor = (int) $notification['id'];
                $visibleModules = array_values(array_filter(
                    $notification['modules'],
                    static function (string $module) use ($permissions): bool {
                        foreach (self::MODULE_PERMISSIONS[$module] ?? [] as $permission) {
                            if (isset($permissions[$permission])) {
                                return true;
                            }
                        }
                        return false;
                    }
                ));
                if ($visibleModules === []) {
                    echo 'id: ' . $cursor . "\n";
                    echo 'event: checkpoint' . "\n";
                    echo 'data: {}' . "\n\n";
                    continue;
                }

                $data = [
                    'event_id' => $notification['event_id'],
                    'type' => $notification['event_type'],
                    'summary' => $notification['summary'],
                    'modules' => $visibleModules,
                    'occurred_at' => $notification['created_at'],
                ];
                echo 'id: ' . $cursor . "\n";
                echo 'event: business-change' . "\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
            }
            if ($notifications !== []) {
                $lastHeartbeat = time();
                flush();
            } elseif (time() - $lastHeartbeat >= 10) {
                echo ': heartbeat ' . gmdate(DATE_ATOM) . "\n\n";
                $lastHeartbeat = time();
                flush();
            }
            usleep(1_000_000);
        }
    }
}
