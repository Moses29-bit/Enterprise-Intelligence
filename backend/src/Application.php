<?php
declare(strict_types=1);

namespace Vantiq;

use PDO;
use Throwable;
use Vantiq\Config\Config;
use Vantiq\Http\AccountController;
use Vantiq\Http\ApiException;
use Vantiq\Http\AuthController;
use Vantiq\Http\BusinessController;
use Vantiq\Http\DataWorkspaceController;
use Vantiq\Http\EnterpriseWorkflowController;
use Vantiq\Http\EventStreamController;
use Vantiq\Http\OutboxController;
use Vantiq\Http\LedgerController;
use Vantiq\Http\Request;
use Vantiq\Http\Response;
use Vantiq\Http\Router;
use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\AuditRepository;
use Vantiq\Repository\BusinessRepository;
use Vantiq\Repository\DataWorkspaceRepository;
use Vantiq\Repository\EnterpriseWorkflowRepository;
use Vantiq\Repository\LoginAttemptRepository;
use Vantiq\Repository\LoginHistoryRepository;
use Vantiq\Repository\LedgerRepository;
use Vantiq\Repository\OrganisationRepository;
use Vantiq\Repository\OutboxRepository;
use Vantiq\Repository\PasswordResetRepository;
use Vantiq\Repository\RoleRepository;
use Vantiq\Repository\UserRepository;
use Vantiq\Security\AuthMiddleware;
use Vantiq\Security\CsrfMiddleware;
use Vantiq\Security\PermissionMiddleware;
use Vantiq\Security\SessionManager;
use Vantiq\Service\AccountService;
use Vantiq\Service\AuthService;
use Vantiq\Service\BusinessService;
use Vantiq\Service\DataWorkspaceService;
use Vantiq\Service\EnterpriseWorkflowService;
use Vantiq\Service\LedgerService;
use Vantiq\Service\PhpMailPasswordResetDelivery;

final class Application
{
    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo,
        private readonly Logger $logger,
        private readonly ?string $databaseFailureReferenceId = null
    ) {
    }

    public function handle(Request $request): void
    {
        $this->securityHeaders();
        if (!$this->handleCors($request)) {
            return;
        }

        if ($request->method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        if ($request->method === 'GET' && $request->path === '/health') {
            $databaseConnected = false;
            if ($this->pdo !== null) {
                try {
                    $this->pdo->query('SELECT 1')->fetchColumn();
                    $databaseConnected = true;
                } catch (Throwable $exception) {
                    $referenceId = $this->logger->exception(
                        'Database health check failed',
                        $exception,
                        ['operation' => 'GET /health', 'stage' => 'health_check']
                    );
                }
            }

            $health = [
                'status' => $databaseConnected ? 'ok' : 'degraded',
                'php_version' => PHP_VERSION,
                'database' => ['connected' => $databaseConnected]
            ];
            if (isset($referenceId)) {
                $health['diagnostic_reference_id'] = $referenceId;
            }
            Response::success($health);
            return;
        }

        if ($this->pdo === null) {
            Response::error(
                'database_unavailable',
                'The database is temporarily unavailable.',
                null,
                503,
                $this->databaseFailureReferenceId
            );
            return;
        }

        $session = new SessionManager($this->config);
        $session->start();

        try {
            (new CsrfMiddleware($session))->handle($request);
            if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && $request->body !== ''
                && !$request->isJson()
                && !$request->isMultipart()) {
                throw new ApiException(415, 'unsupported_media_type', 'Send request data as application/json.');
            }
            if ($request->isMultipart()
                && !in_array($request->path, ['/documents/upload', '/imports/upload'], true)) {
                throw new ApiException(415, 'unsupported_media_type', 'Multipart uploads are not supported for this endpoint.');
            }
            if ($request->isMultipart() && ($request->contentLength() ?? 0) > Request::MAX_MULTIPART_BYTES) {
                throw new ApiException(413, 'payload_too_large', 'The upload request exceeds the 15 MB file size limit.');
            }

            $users = new UserRepository($this->pdo);
            $organisations = new OrganisationRepository($this->pdo);
            $roles = new RoleRepository($this->pdo);
            $audit = new AuditRepository($this->pdo);
            $outbox = new OutboxRepository($this->pdo);
            $attempts = new LoginAttemptRepository($this->pdo);
            $history = new LoginHistoryRepository($this->pdo);
            $resetTokens = new PasswordResetRepository($this->pdo);
            $authMiddleware = new AuthMiddleware($this->config, $session, $users, $organisations);
            $permissionMiddleware = new PermissionMiddleware($roles);
            $authService = new AuthService(
                $this->pdo,
                $this->config,
                $session,
                $users,
                $organisations,
                $roles,
                $audit,
                $attempts,
                $history,
                $resetTokens,
                new PhpMailPasswordResetDelivery($this->config)
            );
            $authController = new AuthController($authService, $authMiddleware, $roles, $session);
            $accountController = new AccountController(
                $authMiddleware,
                $authController,
                new AccountService($this->pdo, $users, $roles, $audit)
            );
            $businessController = new BusinessController(
                new BusinessService(new BusinessRepository($this->pdo, $audit)),
                $authMiddleware,
                $permissionMiddleware
            );
            $ledgerController = new LedgerController(
                new LedgerService(new LedgerRepository($this->pdo, $audit, $outbox)),
                $authMiddleware,
                $permissionMiddleware
            );
            $workflowController = new EnterpriseWorkflowController(
                new EnterpriseWorkflowService(new EnterpriseWorkflowRepository($this->pdo, $audit, $outbox)),
                $authMiddleware,
                $permissionMiddleware
            );
            $dataWorkspaceController = new DataWorkspaceController(
                new DataWorkspaceService(
                    new DataWorkspaceRepository($this->pdo, $audit, $outbox),
                    $this->config,
                    $this->pdo,
                    $this->logger,
                    $outbox
                ),
                $authMiddleware,
                $permissionMiddleware,
                $organisations
            );

            if ($request->method === 'GET' && $request->path === '/events/stream') {
                (new EventStreamController($authMiddleware, $roles, $outbox, $this->logger))->stream($request);
                return;
            }

            $outboxController = new OutboxController($outbox, $authMiddleware, $permissionMiddleware);
            $router = new Router($this->logger);
            $router->add('GET', '#^/auth/csrf$#', static fn (): array => $authController->csrf());
            $router->add('POST', '#^/auth/register$#', static fn (Request $r): array => $authController->register($r));
            $router->add('POST', '#^/auth/login$#', static fn (Request $r): array => $authController->login($r));
            $router->add('POST', '#^/auth/logout$#', static fn (): array => $authController->logout());
            $router->add('GET', '#^/auth/me$#', static fn (): array => $authController->me());
            $router->add('POST', '#^/auth/change-password$#', static fn (Request $r): array => $authController->changePassword($r));
            $router->add('POST', '#^/auth/forgot-password$#', static fn (Request $r): array => $authController->forgotPassword($r));
            $router->add('POST', '#^/auth/reset-password$#', static fn (Request $r): array => $authController->resetPassword($r));
            $router->add(
                'PATCH',
                '#^/auth/accounts/(?P<id>[1-9][0-9]*)/status$#',
                static fn (Request $r, array $params): array => $accountController->updateStatus($r, $params)
            );
            $router->add(
                'PUT',
                '#^/auth/accounts/(?P<id>[1-9][0-9]*)/roles$#',
                static fn (Request $r, array $params): array => $accountController->updateRoles($r, $params)
            );

            $router->add('GET', '#^/finance/summary$#', static fn (): array => $businessController->financeSummary());
            $router->add('GET', '#^/finance/sales$#', static fn (Request $r): array => $ledgerController->sales($r));
            $router->add('POST', '#^/finance/sales$#', static fn (Request $r): array => $ledgerController->createSale($r));
            $router->add('POST', '#^/finance/sales/(?P<id>[1-9][0-9]*)/refund$#', static fn (Request $r, array $params): array => $ledgerController->refundSale($r, $params));
            $router->add('GET', '#^/finance/expenses$#', static fn (Request $r): array => $ledgerController->expenses($r));
            $router->add('POST', '#^/finance/expenses$#', static fn (Request $r): array => $ledgerController->createExpense($r));
            $router->add('GET', '#^/finance/invoices$#', static fn (): array => $workflowController->invoices());
            $router->add('POST', '#^/finance/invoices$#', static fn (Request $r): array => $workflowController->createInvoice($r));
            $router->add('GET', '#^/finance/invoices/(?P<id>[1-9][0-9]*)$#', static fn (Request $r, array $params): array => $workflowController->invoiceDetail($params));
            $router->add('POST', '#^/finance/invoices/(?P<id>[1-9][0-9]*)/payments$#', static fn (Request $r, array $params): array => $workflowController->recordPayment($r, $params));
            $router->add('GET', '#^/finance/invoices/(?P<id>[1-9][0-9]*)/credit-notes$#', static fn (Request $r, array $params): array => $workflowController->creditNotes($params));
            $router->add('POST', '#^/finance/invoices/(?P<id>[1-9][0-9]*)/credit-notes$#', static fn (Request $r, array $params): array => $workflowController->createCreditNote($r, $params));
            $router->add('GET', '#^/finance/customers$#', static fn (): array => $workflowController->customers());
            $router->add('POST', '#^/finance/customers$#', static fn (Request $r): array => $workflowController->createCustomer($r));
            $router->add('GET', '#^/operations/summary$#', static fn (): array => $businessController->operationsSummary());
            $router->add('GET', '#^/operations/products$#', static fn (): array => $ledgerController->products());
            $router->add('POST', '#^/operations/products$#', static fn (Request $r): array => $ledgerController->createProduct($r));
            $router->add('POST', '#^/operations/products/(?P<id>[1-9][0-9]*)/movements$#', static fn (Request $r, array $params): array => $ledgerController->moveStock($r, $params));
            $router->add('GET', '#^/operations/suppliers$#', static fn (): array => $workflowController->suppliers());
            $router->add('POST', '#^/operations/suppliers$#', static fn (Request $r): array => $workflowController->createSupplier($r));
            $router->add('GET', '#^/operations/purchase-orders$#', static fn (): array => $workflowController->purchaseOrders());
            $router->add('POST', '#^/operations/purchase-orders$#', static fn (Request $r): array => $workflowController->createPurchaseOrder($r));
            $router->add('POST', '#^/operations/purchase-orders/(?P<id>[1-9][0-9]*)/submit$#', static fn (Request $r, array $params): array => $workflowController->submitPurchaseOrder($params));
            $router->add('POST', '#^/operations/purchase-orders/(?P<id>[1-9][0-9]*)/receive$#', static fn (Request $r, array $params): array => $workflowController->receivePurchaseOrder($params));
            $router->add('GET', '#^/reports/summary$#', static fn (): array => $businessController->reportsSummary());
            $router->add('GET', '#^/reports/financial$#', static fn (Request $r): array => $ledgerController->report($r));
            $router->add('GET', '#^/forecast/summary$#', static fn (): array => $businessController->forecastSummary());
            $router->add('GET', '#^/risk/alerts$#', static fn (): array => $businessController->riskAlerts());
            $router->add('PATCH', '#^/risk/alerts/(?P<id>[1-9][0-9]*)/status$#', static fn (Request $r, array $params): array => $businessController->riskAlertStatusUpdate($r, $params));
            $router->add('GET', '#^/decisions/scenarios$#', static fn (): array => $businessController->decisionScenarios());
            $router->add('POST', '#^/decisions/scenarios$#', static fn (Request $r): array => $businessController->createDecisionScenario($r));
            $router->add('GET', '#^/decisions/scenarios/(?P<id>[1-9][0-9]*)$#', static fn (Request $r, array $params): array => $businessController->decisionScenarioDetail($params));
            $router->add('GET', '#^/connect/imports$#', static fn (): array => $businessController->importsHistory());
            $router->add('POST', '#^/connect/imports/preview$#', static fn (Request $r): array => $businessController->importPreview($r));
            $router->add('GET', '#^/admin/overview$#', static fn (): array => $businessController->adminOverview());
            $router->add('GET', '#^/admin/users$#', static fn (): array => $businessController->adminUsers());
            $router->add('GET', '#^/admin/branches$#', static fn (): array => $businessController->adminBranches());
            $router->add('GET', '#^/admin/departments$#', static fn (): array => $businessController->adminDepartments());
            $router->add('GET', '#^/admin/roles$#', static fn (): array => $businessController->adminRoles());
            $router->add('GET', '#^/admin/audit-logs$#', static fn (): array => $businessController->adminAuditLogs());
            $router->add('GET', '#^/admin/outbox/failures$#', static fn (): array => $outboxController->failures());
            $router->add(
                'POST',
                '#^/admin/outbox/events/(?P<eventId>[a-fA-F0-9-]{36})/replay$#',
                static fn (Request $r, array $params): array => $outboxController->replay($params)
            );
            $router->add('POST', '#^/admin/branches$#', static fn (Request $r): array => $businessController->createBranch($r));
            $router->add('POST', '#^/admin/departments$#', static fn (Request $r): array => $businessController->createDepartment($r));
            $router->add('GET', '#^/documents$#', static fn (Request $r): array => $dataWorkspaceController->documents($r));
            $router->add('POST', '#^/documents/upload$#', static fn (Request $r): array => $dataWorkspaceController->uploadDocument($r));
            $router->add('GET', '#^/documents/versions/(?P<group>[a-fA-F0-9-]{36})$#', static fn (Request $r, array $params): array => $dataWorkspaceController->documentVersions($params));
            $router->add('GET', '#^/documents/(?P<id>[1-9][0-9]*)/extraction$#', static fn (Request $r, array $params): array => $dataWorkspaceController->documentExtraction($params));
            $router->add('POST', '#^/documents/(?P<id>[1-9][0-9]*)/review$#', static fn (Request $r, array $params): array => $dataWorkspaceController->reviewDocument($params));
            $router->add('GET', '#^/imports$#', static fn (): array => $dataWorkspaceController->importHistory());
            $router->add('POST', '#^/imports/upload$#', static fn (Request $r): array => $dataWorkspaceController->uploadImport($r));
            $router->add('GET', '#^/imports/templates/(?P<type>[a-z]+)$#', static fn (Request $r, array $params): array => $dataWorkspaceController->template($params));
            $router->add('POST', '#^/imports/(?P<id>[1-9][0-9]*)/validate$#', static fn (Request $r, array $params): array => $dataWorkspaceController->validateImport($r, $params));
            $router->add('POST', '#^/imports/(?P<id>[1-9][0-9]*)/commit$#', static fn (Request $r, array $params): array => $dataWorkspaceController->commitImport($params));
            $router->add('POST', '#^/imports/(?P<id>[1-9][0-9]*)/rollback$#', static fn (Request $r, array $params): array => $dataWorkspaceController->rollbackImport($params));
            $router->add('GET', '#^/integrations/organisations$#', static fn (): array => $dataWorkspaceController->integrationOrganisations());
            $router->add('GET', '#^/integrations$#', static fn (Request $r): array => $dataWorkspaceController->integrations($r));
            $router->add('POST', '#^/integrations$#', static fn (Request $r): array => $dataWorkspaceController->createIntegration($r));
            $router->add('POST', '#^/integrations/test$#', static fn (Request $r): array => $dataWorkspaceController->testConnection($r));
            $router->add('POST', '#^/integrations/(?P<id>[1-9][0-9]*)/activate$#', static fn (Request $r, array $params): array => $dataWorkspaceController->activateIntegration($r, $params));
            $router->add('POST', '#^/integrations/(?P<id>[1-9][0-9]*)/sync$#', static fn (Request $r, array $params): array => $dataWorkspaceController->syncIntegration($r, $params));
            $router->dispatch($request);
        } catch (ApiException $exception) {
            Response::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details === [] ? null : $exception->details,
                $exception->status
            );
        } catch (Throwable $exception) {
            $referenceId = $this->logger->exception(
                'Unhandled request exception',
                $exception,
                [
                    'operation' => substr($request->method . ' ' . $request->path, 0, 256),
                    'stage' => 'request_setup',
                    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                    'organisation_id' => isset($_SESSION['organisation_id'])
                        ? (int) $_SESSION['organisation_id']
                        : null
                ]
            );
            Response::error('internal_error', 'The service is temporarily unavailable.', null, 500, $referenceId);
        }
    }

    private function handleCors(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin === null) {
            return true;
        }

        $allowedOrigins = $this->config->get('allowed_origins');
        if (!in_array($origin, $allowedOrigins, true)) {
            Response::error('origin_not_allowed', 'This origin is not allowed.', null, 403);
            return false;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Last-Event-ID');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');
        return true;
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store');
        if ($this->config->isProduction()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
