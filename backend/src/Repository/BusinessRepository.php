<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;

final class BusinessRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?AuditRepository $audit = null
    )
    {
    }

    public function financeSummary(int $organisationId): array
    {
        $revenue = $this->moneyValue(
            $this->pdo->prepare(
                "SELECT COALESCE(SUM(s.total), 0) - COALESCE((
                    SELECT SUM(cn.total)
                    FROM credit_notes cn
                    JOIN invoices i ON i.id = cn.invoice_id AND i.organisation_id = cn.organisation_id
                    WHERE cn.organisation_id = :credit_organisation_id
                 ), 0)
                 FROM sales s
                 WHERE s.organisation_id = :organisation_id AND s.status = 'posted'"
            ),
            ['organisation_id' => $organisationId, 'credit_organisation_id' => $organisationId]
        );

        $refunds = $this->moneyValue(
            $this->pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM sales WHERE organisation_id = :organisation_id AND status = 'refunded'"),
            ['organisation_id' => $organisationId]
        );
        $refunds += $this->moneyValue(
            $this->pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM credit_notes WHERE organisation_id = :organisation_id'),
            ['organisation_id' => $organisationId]
        );

        $costOfGoods = $this->moneyValue(
            $this->pdo->prepare(
                "SELECT COALESCE(SUM(si.quantity * si.unit_cost), 0)
                 FROM sales s
                 JOIN sale_items si ON si.sale_id = s.id
                 WHERE s.organisation_id = :organisation_id AND s.status = 'posted'"
            ),
            ['organisation_id' => $organisationId]
        );
        $costOfGoods -= $this->moneyValue(
            $this->pdo->prepare(
                'SELECT COALESCE(SUM(cni.returned_quantity * cni.unit_cost), 0)
                 FROM credit_note_items cni
                 JOIN credit_notes cn ON cn.id = cni.credit_note_id
                 WHERE cn.organisation_id = :organisation_id'
            ),
            ['organisation_id' => $organisationId]
        );

        $expenseTotals = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN ec.type IN ('operating', 'administrative') THEN e.amount ELSE 0 END), 0) AS operating_expenses,
                COALESCE(SUM(CASE WHEN ec.type IN ('inventory', 'other') THEN e.amount ELSE 0 END), 0) AS other_expenses,
                COALESCE(SUM(CASE WHEN e.category_id IS NULL THEN e.amount ELSE 0 END), 0) AS unclassified_expenses,
                COALESCE(SUM(e.amount), 0) AS total_expenses
             FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.category_id AND ec.organisation_id = e.organisation_id
             WHERE e.organisation_id = :organisation_id"
        );
        $expenseTotals->execute(['organisation_id' => $organisationId]);
        $expenseRow = $expenseTotals->fetch() ?: [];
        $operatingExpenses = round((float) ($expenseRow['operating_expenses'] ?? 0), 2);
        $otherExpenses = round((float) ($expenseRow['other_expenses'] ?? 0), 2);
        $unclassifiedExpenses = round((float) ($expenseRow['unclassified_expenses'] ?? 0), 2);
        $totalExpenses = round((float) ($expenseRow['total_expenses'] ?? 0), 2);
        $grossProfit = round($revenue - $costOfGoods, 2);
        $operatingProfit = round($grossProfit - $operatingExpenses, 2);

        $receivables = $this->moneyValue(
            $this->pdo->prepare(
                'SELECT COALESCE(SUM(GREATEST(
                    i.total
                    - COALESCE((SELECT SUM(cn.total) FROM credit_notes cn
                                WHERE cn.invoice_id = i.id AND cn.organisation_id = i.organisation_id), 0)
                    - COALESCE((SELECT SUM(p.amount) FROM payments p
                                WHERE p.invoice_id = i.id AND p.organisation_id = i.organisation_id), 0)
                    + COALESCE((SELECT SUM(ir.amount) FROM invoice_refunds ir
                                WHERE ir.invoice_id = i.id AND ir.organisation_id = i.organisation_id), 0),
                    0)), 0)
                 FROM invoices i
                 WHERE i.organisation_id = :organisation_id AND i.status IN (\'issued\', \'partial\', \'overdue\')'
            ),
            ['organisation_id' => $organisationId]
        );

        $paymentsCollected = $this->moneyValue(
            $this->pdo->prepare(
                'SELECT COALESCE((SELECT SUM(amount) FROM payments WHERE organisation_id = :payment_organisation_id), 0)
                     - COALESCE((SELECT SUM(amount) FROM invoice_refunds WHERE organisation_id = :refund_organisation_id), 0)'
            ),
            ['payment_organisation_id' => $organisationId, 'refund_organisation_id' => $organisationId]
        );

        $grossMargin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0.0;
        $operatingMargin = $revenue > 0 ? ($operatingProfit / $revenue) * 100 : 0.0;

        $branchBreakdown = $this->pdo->prepare(
            "SELECT b.name AS label,
                    COALESCE(SUM(s.total), 0) - COALESCE((
                        SELECT SUM(cn.total) FROM credit_notes cn
                        JOIN invoices i ON i.id = cn.invoice_id AND i.organisation_id = cn.organisation_id
                        WHERE cn.organisation_id = :credit_organisation_id AND i.branch_id = b.id
                    ), 0) AS value
             FROM branches b
             LEFT JOIN sales s ON s.branch_id = b.id AND s.organisation_id = :sales_organisation_id
                AND s.status = 'posted'
             WHERE b.organisation_id = :branch_organisation_id
             GROUP BY b.id, b.name
             ORDER BY value DESC, b.name ASC"
        );
        $branchBreakdown->execute([
            'sales_organisation_id' => $organisationId,
            'credit_organisation_id' => $organisationId,
            'branch_organisation_id' => $organisationId,
        ]);

        $departmentBreakdown = $this->pdo->prepare(
            "SELECT d.name AS label, COALESCE(SUM(s.total), 0) AS value
             FROM departments d
             LEFT JOIN sales s ON s.department_id = d.id AND s.organisation_id = :sales_organisation_id
                AND s.status = 'posted'
             WHERE d.organisation_id = :department_organisation_id
             GROUP BY d.id, d.name
             ORDER BY value DESC, d.name ASC"
        );
        $departmentBreakdown->execute([
            'sales_organisation_id' => $organisationId,
            'department_organisation_id' => $organisationId,
        ]);

        $recentActivity = $this->pdo->prepare(
            'SELECT action_type AS type, entity_name AS entity, JSON_UNQUOTE(JSON_EXTRACT(details, "$.summary")) AS summary,
                    DATE_FORMAT(created_at, "%Y-%m-%d") AS created_at
             FROM audit_logs
             WHERE organisation_id = :organisation_id
             ORDER BY created_at DESC
             LIMIT 5'
        );
        $recentActivity->execute(['organisation_id' => $organisationId]);

        return [
            'kpis' => [
                ['label' => 'Net revenue', 'value' => $revenue],
                ['label' => 'Operating expenses', 'value' => $operatingExpenses],
                ['label' => 'Operating profit', 'value' => $operatingProfit],
                ['label' => 'Payments collected', 'value' => $paymentsCollected],
            ],
            'summary' => [
                'totalRevenue' => $revenue,
                'refunds' => $refunds,
                'costOfGoodsSold' => $costOfGoods,
                'grossProfit' => $grossProfit,
                'grossMargin' => round($grossMargin, 2),
                'operatingExpenses' => $operatingExpenses,
                'otherExpenses' => $otherExpenses,
                'unclassifiedExpenses' => $unclassifiedExpenses,
                'totalExpenses' => $totalExpenses,
                'operatingProfit' => $operatingProfit,
                'operatingMargin' => round($operatingMargin, 2),
                'paymentsCollected' => $paymentsCollected,
                'outstandingReceivables' => $receivables,
            ],
            'branchBreakdown' => $branchBreakdown->fetchAll(),
            'departmentBreakdown' => $departmentBreakdown->fetchAll(),
            'recentActivity' => $recentActivity->fetchAll(),
            'source' => 'database-backed finance summary',
        ];
    }

    public function operationsSummary(int $organisationId): array
    {
        $inventoryValue = $this->moneyValue(
            $this->pdo->prepare(
                'SELECT COALESCE(SUM(stock_level * unit_cost), 0) FROM products WHERE organisation_id = :organisation_id'
            ),
            ['organisation_id' => $organisationId]
        );

        $lowStock = $this->pdo->prepare(
            'SELECT p.name, p.sku, p.stock_level, p.reorder_level,
                    COALESCE((SELECT b.name FROM branches b WHERE b.id = p.branch_id AND b.organisation_id = :branch_organisation_id), "Unassigned") AS branch_name
             FROM products p
             WHERE p.organisation_id = :product_organisation_id AND p.stock_level <= p.reorder_level
             ORDER BY p.stock_level ASC, p.name ASC
             LIMIT 10'
        );
        $lowStock->execute([
            'branch_organisation_id' => $organisationId,
            'product_organisation_id' => $organisationId,
        ]);
        $lowStockRows = $lowStock->fetchAll();

        $purchaseOrders = $this->pdo->prepare(
            'SELECT po.id, s.name AS supplier_name, po.status, po.total, DATE_FORMAT(po.order_date, "%Y-%m-%d") AS order_date
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id AND s.organisation_id = po.organisation_id
             WHERE po.organisation_id = :organisation_id
             ORDER BY po.order_date DESC
             LIMIT 10'
        );
        $purchaseOrders->execute(['organisation_id' => $organisationId]);

        $movements = $this->pdo->prepare(
            'SELECT movement_type AS type, quantity, DATE_FORMAT(created_at, "%Y-%m-%d") AS created_at, notes
             FROM stock_movements
             WHERE organisation_id = :organisation_id
             ORDER BY created_at DESC
             LIMIT 8'
        );
        $movements->execute(['organisation_id' => $organisationId]);

        return [
            'inventoryValue' => $inventoryValue,
            'lowStock' => $lowStockRows,
            'purchaseOrders' => $purchaseOrders->fetchAll(),
            'recentMovements' => $movements->fetchAll(),
            'stockHealth' => [
                'lowStockCount' => count($lowStockRows),
                'purchaseOrderCount' => $this->countValue(
                    $this->pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE organisation_id = :organisation_id'),
                    ['organisation_id' => $organisationId]
                ),
            ],
            'source' => 'database-backed operations summary',
        ];
    }

    public function reportsSummary(int $organisationId): array
    {
        $totals = $this->financeSummary($organisationId);
        $salesCount = $this->countValue(
            $this->pdo->prepare('SELECT COUNT(*) FROM sales WHERE organisation_id = :organisation_id'),
            ['organisation_id' => $organisationId]
        );
        $customerCount = $this->countValue(
            $this->pdo->prepare('SELECT COUNT(*) FROM customers WHERE organisation_id = :organisation_id'),
            ['organisation_id' => $organisationId]
        );
        return [
            'availableReports' => ['financial', 'revenue', 'expense', 'inventory', 'risk', 'forecast', 'decisions'],
            'totals' => $totals['summary'],
            'records' => [
                'salesCount' => $salesCount,
                'customerCount' => $customerCount,
            ],
            'source' => 'database-backed reports',
        ];
    }

    public function forecastSummary(int $organisationId): array
    {
        $forecastCount = $this->countValue(
            $this->pdo->prepare('SELECT COUNT(*) FROM forecasts WHERE organisation_id = :organisation_id'),
            ['organisation_id' => $organisationId]
        );

        if ($forecastCount < 1) {
            return [
                'available' => false,
                'message' => 'Forecasting is unavailable until sufficient historical revenue and inventory data is recorded.',
                'source' => 'database-backed forecast model',
            ];
        }

        $latest = $this->pdo->prepare(
            'SELECT forecast_type, methodology, model_version, data_coverage, confidence_interval, assumptions, generated_at
             FROM forecasts
             WHERE organisation_id = :organisation_id
             ORDER BY generated_at DESC
             LIMIT 1'
        );
        $latest->execute(['organisation_id' => $organisationId]);
        $forecast = $latest->fetch();

        $results = $this->pdo->prepare(
            'SELECT period_label, projected_value, actual_value
             FROM forecast_results fr
             JOIN forecasts f ON f.id = fr.forecast_id
             WHERE f.organisation_id = :organisation_id
             ORDER BY fr.created_at DESC
             LIMIT 6'
        );
        $results->execute(['organisation_id' => $organisationId]);

        return [
            'available' => true,
            'forecast' => $forecast,
            'results' => $results->fetchAll(),
            'methodology' => $forecast['methodology'] ?? 'Weighted moving average seasonality calibration',
            'source' => 'database-backed forecast model',
        ];
    }

    public function riskAlerts(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, category, severity, business_area, evidence, explanation, suggested_action, reviewer_user_id, status, DATE_FORMAT(detected_at, "%Y-%m-%d") AS detected_at
             FROM risk_alerts
             WHERE organisation_id = :organisation_id
             ORDER BY detected_at DESC, FIELD(severity, "critical", "high", "medium", "low")
             LIMIT 20'
        );
        $statement->execute(['organisation_id' => $organisationId]);

        return [
            'alerts' => $statement->fetchAll(),
            'source' => 'database-backed risk review',
        ];
    }

    public function decisionScenarios(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ds.id, ds.name, ds.description, ds.status, ds.created_at,
                    (SELECT COUNT(*) FROM scenario_results sr WHERE sr.scenario_id = ds.id) AS result_count
             FROM decision_scenarios ds
             WHERE ds.organisation_id = :organisation_id
             ORDER BY ds.created_at DESC
             LIMIT 20'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return [
            'scenarios' => $statement->fetchAll(),
            'source' => 'decision studio',
        ];
    }

    public function importsHistory(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, file_name, status, mime_type, file_size, DATE_FORMAT(uploaded_at, "%Y-%m-%d") AS uploaded_at
             FROM imported_files
             WHERE organisation_id = :organisation_id
             ORDER BY uploaded_at DESC
             LIMIT 20'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return [
            'imports' => $statement->fetchAll(),
            'source' => 'connect import history',
        ];
    }

    public function adminOverview(int $organisationId): array
    {
        $userCount = $this->countValue(
            $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE organisation_id = :organisation_id'),
            ['organisation_id' => $organisationId]
        );
        $branchCount = $this->countValue(
            $this->pdo->prepare('SELECT COUNT(*) FROM branches WHERE organisation_id = :organisation_id'),
            ['organisation_id' => $organisationId]
        );
        $departmentCount = $this->countValue(
            $this->pdo->prepare('SELECT COUNT(*) FROM departments WHERE organisation_id = :organisation_id'),
            ['organisation_id' => $organisationId]
        );
        $auditCount = $this->countValue(
            $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE organisation_id = :organisation_id'),
            ['organisation_id' => $organisationId]
        );

        return [
            'stats' => [
                'users' => $userCount,
                'branches' => $branchCount,
                'departments' => $departmentCount,
                'auditEvents' => $auditCount,
            ],
            'source' => 'organisation administration',
        ];
    }

    public function adminUsers(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.full_name AS name, u.email, u.status, u.last_login_at,
                    GROUP_CONCAT(DISTINCT r.id ORDER BY r.id SEPARATOR ",") AS role_ids,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ", ") AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id AND r.organisation_id = u.organisation_id
             WHERE u.organisation_id = :organisation_id
             GROUP BY u.id, u.full_name, u.email, u.status, u.last_login_at
             ORDER BY u.full_name ASC'
        );
        $statement->execute(['organisation_id' => $organisationId]);

        return [
            'users' => $statement->fetchAll(),
            'source' => 'organisation user directory',
        ];
    }

    public function adminBranches(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.name, b.code
             FROM branches b
             WHERE b.organisation_id = :organisation_id
             ORDER BY b.name ASC'
        );
        $statement->execute(['organisation_id' => $organisationId]);

        return [
            'branches' => $statement->fetchAll(),
            'source' => 'organisation branch directory',
        ];
    }

    public function adminDepartments(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.name, d.code, d.branch_id
             FROM departments d
             WHERE d.organisation_id = :organisation_id
             ORDER BY d.name ASC'
        );
        $statement->execute(['organisation_id' => $organisationId]);

        return [
            'departments' => $statement->fetchAll(),
            'source' => 'organisation department directory',
        ];
    }

    public function adminRoles(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, description
             FROM roles
             WHERE organisation_id = :organisation_id AND is_system = 0
             ORDER BY name ASC'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return ['roles' => $statement->fetchAll()];
    }

    public function createBranch(int $organisationId, int $userId, string $name, string $code): array
    {
        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(
                'INSERT INTO branches (organisation_id, name, code, created_at)
                 VALUES (:organisation_id, :name, :code, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'organisation_id' => $organisationId,
                'name' => $name,
                'code' => strtoupper($code),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit?->record($organisationId, $userId, 'branch.created', 'branch', ['branch_id' => $id]);
            $this->pdo->commit();
            return ['id' => $id, 'name' => $name, 'code' => strtoupper($code)];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof \PDOException && (string) $exception->getCode() === '23000') {
                throw new \Vantiq\Http\ApiException(409, 'duplicate_branch_code', 'A branch with this code already exists in your organisation.');
            }
            throw $exception;
        }
    }

    public function createDepartment(int $organisationId, int $userId, string $name, string $code, ?int $branchId): array
    {
        try {
            $this->pdo->beginTransaction();
            if ($branchId !== null) {
                $ownerCheck = $this->pdo->prepare(
                    'SELECT 1 FROM branches WHERE id = :branch_id AND organisation_id = :organisation_id LIMIT 1'
                );
                $ownerCheck->execute(['branch_id' => $branchId, 'organisation_id' => $organisationId]);
                if ($ownerCheck->fetchColumn() === false) {
                    throw new \Vantiq\Http\ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                        'branch_id' => 'The selected branch does not belong to your organisation.'
                    ]);
                }
            }
            $statement = $this->pdo->prepare(
                'INSERT INTO departments (organisation_id, branch_id, name, code, created_at)
                 VALUES (:organisation_id, :branch_id, :name, :code, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'organisation_id' => $organisationId,
                'branch_id' => $branchId,
                'name' => $name,
                'code' => strtoupper($code),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit?->record($organisationId, $userId, 'department.created', 'department', ['department_id' => $id]);
            $this->pdo->commit();
            return ['id' => $id, 'name' => $name, 'code' => strtoupper($code), 'branch_id' => $branchId];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof \PDOException && (string) $exception->getCode() === '23000') {
                throw new \Vantiq\Http\ApiException(409, 'duplicate_department_code', 'A department with this code already exists in your organisation.');
            }
            throw $exception;
        }
    }

    public function riskAlertStatusUpdate(int $organisationId, int $alertId, string $status, ?int $reviewerUserId, ?string $resolutionNotes): array
    {
        $allowed = ['open', 'assigned', 'reviewing', 'resolved', 'dismissed'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported alert status.');
        }

        $existing = $this->pdo->prepare(
            'SELECT id, status, reviewer_user_id, resolution_notes FROM risk_alerts
             WHERE id = :id AND organisation_id = :organisation_id LIMIT 1'
        );
        $existing->execute(['id' => $alertId, 'organisation_id' => $organisationId]);
        $alert = $existing->fetch();
        if ($alert === false) {
            throw new \Vantiq\Http\ApiException(404, 'risk_alert_not_found', 'The alert was not found in your organisation.');
        }

        $reviewerId = $reviewerUserId;
        if ($reviewerId !== null) {
            $ownerCheck = $this->pdo->prepare(
                'SELECT 1 FROM users WHERE id = :reviewer_id AND organisation_id = :organisation_id LIMIT 1'
            );
            $ownerCheck->execute(['reviewer_id' => $reviewerId, 'organisation_id' => $organisationId]);
            if ($ownerCheck->fetchColumn() === false) {
                throw new \Vantiq\Http\ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'reviewer_user_id' => 'The reviewer must belong to your organisation.'
                ]);
            }
        }

        $statement = $this->pdo->prepare(
            'UPDATE risk_alerts
             SET status = :status,
                 reviewer_user_id = :reviewer_user_id,
                 resolution_notes = :resolution_notes
             WHERE id = :id AND organisation_id = :organisation_id'
        );
        $statement->execute([
            'status' => $status,
            'reviewer_user_id' => $reviewerId,
            'resolution_notes' => $resolutionNotes,
            'id' => $alertId,
            'organisation_id' => $organisationId,
        ]);

        return [
            'updated' => (bool) $statement->rowCount(),
            'id' => $alertId,
            'status' => $status,
            'source' => 'risk workflow update',
        ];
    }

    public function createDecisionScenario(int $organisationId, int $userId, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Scenario name is required.');
        }

        $status = in_array(($input['status'] ?? 'draft'), ['draft', 'active', 'archived'], true)
            ? (string) ($input['status'] ?? 'draft')
            : 'draft';

        $assumptions = is_array($input['assumptions'] ?? null) ? $input['assumptions'] : [];
        $this->pdo->beginTransaction();
        try {
            $scenarioInsert = $this->pdo->prepare(
                'INSERT INTO decision_scenarios (organisation_id, name, description, status, created_by_user_id, created_at)
                 VALUES (:organisation_id, :name, :description, :status, :user_id, CURRENT_TIMESTAMP)'
            );
            $scenarioInsert->execute([
                'organisation_id' => $organisationId,
                'name' => $name,
                'description' => $description,
                'status' => $status,
                'user_id' => $userId,
            ]);
            $scenarioId = (int) $this->pdo->lastInsertId();

            if ($assumptions !== []) {
                $assumptionInsert = $this->pdo->prepare(
                    'INSERT INTO scenario_assumptions (scenario_id, assumption_key, assumption_value, comment, created_at)
                     VALUES (:scenario_id, :assumption_key, :assumption_value, :comment, CURRENT_TIMESTAMP)'
                );
                foreach ($assumptions as $key => $value) {
                    $assumptionInsert->execute([
                        'scenario_id' => $scenarioId,
                        'assumption_key' => (string) $key,
                        'assumption_value' => json_encode($value, JSON_THROW_ON_ERROR),
                        'comment' => null,
                    ]);
                }
            }

            $baseRevenue = (float) $this->moneyValue(
                $this->pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM sales WHERE organisation_id = :organisation_id'),
                ['organisation_id' => $organisationId]
            );
            $baseExpenses = (float) $this->moneyValue(
                $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE organisation_id = :organisation_id'),
                ['organisation_id' => $organisationId]
            );

            $growth = (float) (($assumptions['revenue_growth'] ?? 0) / 100);
            $priceIncrease = (float) (($assumptions['price_increase'] ?? 0) / 100);
            $costReduction = (float) (($assumptions['cost_reduction'] ?? 0) / 100);
            $projectedRevenue = $baseRevenue * (1 + $growth + $priceIncrease);
            $projectedExpenses = $baseExpenses * (1 - $costReduction);
            $profit = $projectedRevenue - $projectedExpenses;

            $resultInsert = $this->pdo->prepare(
                'INSERT INTO scenario_results (scenario_id, label, metric_name, actual_value, projected_value, variance, created_at)
                 VALUES (:scenario_id, :label, :metric_name, :actual_value, :projected_value, :variance, CURRENT_TIMESTAMP)'
            );
            $resultInsert->execute([
                'scenario_id' => $scenarioId,
                'label' => 'Projected revenue',
                'metric_name' => 'revenue',
                'actual_value' => $baseRevenue,
                'projected_value' => round($projectedRevenue, 2),
                'variance' => round($projectedRevenue - $baseRevenue, 2),
            ]);
            $resultInsert->execute([
                'scenario_id' => $scenarioId,
                'label' => 'Projected operating profit',
                'metric_name' => 'operating_profit',
                'actual_value' => $baseRevenue - $baseExpenses,
                'projected_value' => round($profit, 2),
                'variance' => round($profit - ($baseRevenue - $baseExpenses), 2),
            ]);

            $this->pdo->commit();

            return [
                'scenario' => $this->decisionScenarioDetail($organisationId, $scenarioId),
                'source' => 'decision scenario creation workflow',
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function decisionScenarioDetail(int $organisationId, int $scenarioId): array
    {
        $scenario = $this->pdo->prepare(
            'SELECT ds.id, ds.name, ds.description, ds.status, ds.created_at, u.name AS created_by
             FROM decision_scenarios ds
             LEFT JOIN users u ON u.id = ds.created_by_user_id
             WHERE ds.id = :scenario_id AND ds.organisation_id = :organisation_id
             LIMIT 1'
        );
        $scenario->execute(['scenario_id' => $scenarioId, 'organisation_id' => $organisationId]);
        $result = $scenario->fetch();
        if ($result === false) {
            throw new \RuntimeException('Decision scenario not found for this organisation.');
        }

        $assumptions = $this->pdo->prepare(
            'SELECT assumption_key, assumption_value, comment FROM scenario_assumptions WHERE scenario_id = :scenario_id ORDER BY id ASC'
        );
        $assumptions->execute(['scenario_id' => $scenarioId]);

        $results = $this->pdo->prepare(
            'SELECT label, metric_name, actual_value, projected_value, variance FROM scenario_results WHERE scenario_id = :scenario_id ORDER BY id ASC'
        );
        $results->execute(['scenario_id' => $scenarioId]);

        $decodedAssumptions = [];
        foreach ($assumptions->fetchAll() as $item) {
            $decodedAssumptions[$item['assumption_key']] = json_decode((string) $item['assumption_value'], true);
        }

        return [
            'scenario' => $result,
            'assumptions' => $decodedAssumptions,
            'results' => $results->fetchAll(),
            'source' => 'decision scenario detail',
        ];
    }

    public function importPreview(int $organisationId, int $userId, string $fileName, string $csvData): array
    {
        $trimmed = trim($csvData);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('CSV content is required.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $trimmed);
        if ($lines === false || count($lines) < 2) {
            throw new \InvalidArgumentException('The uploaded file must include a header row and at least one data row.');
        }

        $headers = str_getcsv($lines[0]);
        $sampleRows = [];
        $errorRows = [];
        $validRows = 0;

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if (trim((string) $lines[$i]) === '') {
                continue;
            }

            $row = str_getcsv((string) $lines[$i]);
            if (count($row) !== count($headers)) {
                $errorRows[] = ['row' => $i + 1, 'error' => 'Column count mismatch.'];
                continue;
            }

            $record = array_combine($headers, $row);
            if ($record === false) {
                $errorRows[] = ['row' => $i + 1, 'error' => 'Unable to parse row.'];
                continue;
            }

            $validRows++;
            if (count($sampleRows) < 10) {
                $sampleRows[] = $record;
            }
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO imported_files (organisation_id, file_name, file_size, mime_type, status, uploaded_by_user_id, uploaded_at)
             VALUES (:organisation_id, :file_name, :file_size, :mime_type, :status, :uploaded_by_user_id, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'file_name' => $fileName,
            'file_size' => strlen($csvData),
            'mime_type' => 'text/csv',
            'status' => 'validated',
            'uploaded_by_user_id' => $userId,
        ]);

        return [
            'import' => [
                'id' => (int) $this->pdo->lastInsertId(),
                'file_name' => $fileName,
                'status' => 'validated',
            ],
            'headers' => $headers,
            'sample_rows' => $sampleRows,
            'valid_row_count' => $validRows,
            'error_rows' => $errorRows,
            'source' => 'connect csv validation',
        ];
    }

    private function moneyValue(\PDOStatement $statement, array $params): float
    {
        $statement->execute($params);
        $value = (float) ($statement->fetchColumn() ?: 0);
        return round($value, 2);
    }

    private function countValue(\PDOStatement $statement, array $params): int
    {
        $statement->execute($params);
        return (int) ($statement->fetchColumn() ?: 0);
    }

}
