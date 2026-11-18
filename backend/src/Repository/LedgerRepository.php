<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;
use Vantiq\Http\ApiException;

final class LedgerRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditRepository $audit,
        private readonly ?OutboxRepository $outbox = null
    ) {
    }

    public function products(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.name, p.sku, p.unit_cost, p.selling_price, p.stock_level, p.reorder_level,
                    p.branch_id, b.name AS branch_name, p.category_id, pc.name AS category_name
             FROM products p
             LEFT JOIN branches b ON b.id = p.branch_id AND b.organisation_id = p.organisation_id
             LEFT JOIN product_categories pc ON pc.id = p.category_id AND pc.organisation_id = p.organisation_id
             WHERE p.organisation_id = :organisation_id
             ORDER BY p.name ASC'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return ['products' => $statement->fetchAll()];
    }

    public function createProduct(int $organisationId, int $userId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $branchId = $input['branch_id'] ?? null;
            $categoryId = $input['category_id'] ?? null;
            if ($branchId !== null) {
                $this->assertOwned('branches', (int) $branchId, $organisationId, 'branch_id');
            }
            if ($categoryId !== null) {
                $this->assertOwned('product_categories', (int) $categoryId, $organisationId, 'category_id');
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO products
                    (organisation_id, branch_id, category_id, name, sku, unit_cost, selling_price, stock_level, reorder_level, created_at)
                 VALUES
                    (:organisation_id, :branch_id, :category_id, :name, :sku, :unit_cost, :selling_price, 0, :reorder_level, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'organisation_id' => $organisationId,
                'branch_id' => $branchId,
                'category_id' => $categoryId,
                'name' => $input['name'],
                'sku' => $input['sku'],
                'unit_cost' => $input['unit_cost'],
                'selling_price' => $input['selling_price'],
                'reorder_level' => $input['reorder_level'],
            ]);
            $productId = (int) $this->pdo->lastInsertId();
            $this->audit->record($organisationId, $userId, 'product.created', 'product', ['product_id' => $productId]);
            $this->outbox?->enqueue($organisationId, 'product', $productId, 'ProductCreated', ['product_id' => $productId]);
            $this->pdo->commit();
            return ['id' => $productId, 'stock_level' => 0];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof \PDOException && (string) $exception->getCode() === '23000') {
                throw new ApiException(409, 'duplicate_sku', 'A product with this SKU already exists in your organisation.');
            }
            throw $exception;
        }
    }

    public function createSale(int $organisationId, int $userId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $branchId = $input['branch_id'] ?? null;
            $departmentId = $input['department_id'] ?? null;
            $customerId = $input['customer_id'] ?? null;
            $this->assertOptionalOwned('branches', $branchId, $organisationId, 'branch_id');
            $this->assertOptionalOwned('departments', $departmentId, $organisationId, 'department_id');
            $this->assertOptionalOwned('customers', $customerId, $organisationId, 'customer_id');

            $items = [];
            $subtotal = 0.0;
            $productQuery = $this->pdo->prepare(
                'SELECT id, name, selling_price, unit_cost, stock_level, branch_id
                 FROM products
                 WHERE id = :id AND organisation_id = :organisation_id
                 ' . $this->lockSuffix()
            );
            foreach ($input['items'] as $item) {
                $productQuery->execute([
                    'id' => $item['product_id'],
                    'organisation_id' => $organisationId,
                ]);
                $product = $productQuery->fetch();
                if ($product === false) {
                    throw new ApiException(404, 'product_not_found', 'A selected product was not found in your organisation.');
                }
                if ($branchId !== null && $product['branch_id'] !== null && (int) $product['branch_id'] !== (int) $branchId) {
                    throw new ApiException(422, 'validation_failed', 'A product is not assigned to the selected branch.', [
                        'items' => 'Use products assigned to this branch.'
                    ]);
                }
                $quantity = (int) $item['quantity'];
                if ((int) $product['stock_level'] < $quantity) {
                    throw new ApiException(409, 'insufficient_stock', 'There is not enough stock to post this sale.', [
                        'product_id' => (int) $product['id'],
                        'available' => (int) $product['stock_level'],
                    ]);
                }
                $price = (float) $product['selling_price'];
                $lineTotal = round($price * $quantity, 2);
                $subtotal = round($subtotal + $lineTotal, 2);
                $items[] = [
                    'product_id' => (int) $product['id'],
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'unit_cost' => (float) $product['unit_cost'],
                    'total' => $lineTotal,
                    'name' => $product['name'],
                ];
            }

            $discount = (float) ($input['discount'] ?? 0);
            $tax = (float) ($input['tax'] ?? 0);
            if ($discount > $subtotal) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'discount' => 'Discount cannot exceed the sale subtotal.'
                ]);
            }
            $total = round($subtotal - $discount + $tax, 2);
            if ($total < 0) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'tax' => 'The sale total cannot be negative.'
                ]);
            }

            $sale = $this->pdo->prepare(
                "INSERT INTO sales
                    (organisation_id, branch_id, department_id, customer_id, sale_date, subtotal, discount, tax, total, status, created_at)
                 VALUES
                    (:organisation_id, :branch_id, :department_id, :customer_id, :sale_date, :subtotal, :discount, :tax, :total, 'posted', CURRENT_TIMESTAMP)"
            );
            $sale->execute([
                'organisation_id' => $organisationId,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'customer_id' => $customerId,
                'sale_date' => $input['sale_date'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
            ]);
            $saleId = (int) $this->pdo->lastInsertId();
            $itemInsert = $this->pdo->prepare(
                'INSERT INTO sale_items (organisation_id, sale_id, product_id, quantity, unit_price, unit_cost, discount, total)
                 VALUES (:organisation_id, :sale_id, :product_id, :quantity, :unit_price, :unit_cost, 0, :total)'
            );
            $stockUpdate = $this->pdo->prepare(
                'UPDATE products SET stock_level = stock_level - :quantity
                 WHERE id = :product_id AND organisation_id = :organisation_id AND stock_level >= :minimum'
            );
            $movementInsert = $this->pdo->prepare(
                "INSERT INTO stock_movements
                    (organisation_id, product_id, branch_id, movement_type, quantity, unit_cost, reference_type, reference_id, notes, created_at)
                 VALUES
                    (:organisation_id, :product_id, :branch_id, 'sale', :quantity, :unit_cost, 'sale', :reference_id, :notes, CURRENT_TIMESTAMP)"
            );
            foreach ($items as $item) {
                $itemInsert->execute([
                    'organisation_id' => $organisationId,
                    'sale_id' => $saleId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['unit_cost'],
                    'total' => $item['total'],
                ]);
                $stockUpdate->execute([
                    'quantity' => $item['quantity'],
                    'product_id' => $item['product_id'],
                    'organisation_id' => $organisationId,
                    'minimum' => $item['quantity'],
                ]);
                if ($stockUpdate->rowCount() !== 1) {
                    throw new ApiException(409, 'insufficient_stock', 'Stock changed while the sale was being posted. Please retry.');
                }
                $movementInsert->execute([
                    'organisation_id' => $organisationId,
                    'product_id' => $item['product_id'],
                    'branch_id' => $branchId,
                    'quantity' => -$item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'reference_id' => $saleId,
                    'notes' => 'Stock issued for posted sale.',
                ]);
            }

            $this->audit->record($organisationId, $userId, 'sale.posted', 'sale', [
                'sale_id' => $saleId,
                'total' => $total,
                'item_count' => count($items),
            ]);
            $this->outbox?->enqueue($organisationId, 'sale', $saleId, 'SaleCreated', [
                'sale_id' => $saleId,
                'total' => $total,
                'item_count' => count($items),
            ]);
            $this->pdo->commit();
            return ['id' => $saleId, 'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total, 'status' => 'posted'];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function sales(int $organisationId, int $limit = 50, int $offset = 0): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.sale_date, s.subtotal, s.discount, s.tax, s.total, s.status,
                    b.name AS branch_name, c.name AS customer_name,
                    (SELECT i.invoice_number FROM invoices i
                     WHERE i.sale_id = s.id AND i.organisation_id = s.organisation_id LIMIT 1) AS invoice_number,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
             FROM sales s
             LEFT JOIN branches b ON b.id = s.branch_id AND b.organisation_id = s.organisation_id
             LEFT JOIN customers c ON c.id = s.customer_id AND c.organisation_id = s.organisation_id
             WHERE s.organisation_id = :organisation_id
             ORDER BY s.sale_date DESC, s.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('organisation_id', $organisationId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['sales' => $statement->fetchAll(), 'limit' => $limit, 'offset' => $offset];
    }

    public function refundSale(int $organisationId, int $userId, int $saleId, string $reason): array
    {
        $this->pdo->beginTransaction();
        try {
            $saleQuery = $this->pdo->prepare(
                'SELECT id, branch_id, status, total,
                        (SELECT i.id FROM invoices i
                         WHERE i.sale_id = sales.id AND i.organisation_id = sales.organisation_id LIMIT 1) AS invoice_id
                 FROM sales
                 WHERE id = :sale_id AND organisation_id = :organisation_id' . $this->lockSuffix()
            );
            $saleQuery->execute(['sale_id' => $saleId, 'organisation_id' => $organisationId]);
            $sale = $saleQuery->fetch();
            if ($sale === false) {
                throw new ApiException(404, 'sale_not_found', 'The sale was not found in your organisation.');
            }
            if ($sale['invoice_id'] !== null) {
                throw new ApiException(409, 'invoice_credit_required', 'Invoice-linked sales require an invoice credit workflow and cannot be refunded as point-of-sale transactions.');
            }
            if ($sale['status'] !== 'posted') {
                throw new ApiException(409, 'sale_not_refundable', 'Only a posted sale can be refunded.');
            }

            $items = $this->pdo->prepare('SELECT product_id, quantity, unit_cost FROM sale_items WHERE sale_id = :sale_id');
            $items->execute(['sale_id' => $saleId]);
            $restock = $this->pdo->prepare(
                'UPDATE products
                 SET unit_cost = CASE
                        WHEN stock_level + :quantity_for_cost > 0
                        THEN ((stock_level * unit_cost) + (:quantity_for_average * :unit_cost)) / (stock_level + :quantity_for_divisor)
                        ELSE unit_cost
                     END,
                     stock_level = stock_level + :quantity
                 WHERE id = :product_id AND organisation_id = :organisation_id'
            );
            $movement = $this->pdo->prepare(
                "INSERT INTO stock_movements
                    (organisation_id, product_id, branch_id, movement_type, quantity, unit_cost, reference_type, reference_id, notes, created_at)
                 VALUES
                    (:organisation_id, :product_id, :branch_id, 'return', :quantity, :unit_cost, 'sale_refund', :reference_id, :notes, CURRENT_TIMESTAMP)"
            );
            foreach ($items->fetchAll() as $item) {
                $restock->execute([
                    'quantity' => $item['quantity'],
                    'quantity_for_cost' => $item['quantity'],
                    'quantity_for_average' => $item['quantity'],
                    'quantity_for_divisor' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'product_id' => $item['product_id'],
                    'organisation_id' => $organisationId,
                ]);
                if ($restock->rowCount() !== 1) {
                    throw new ApiException(409, 'product_unavailable', 'A sale product is no longer available for a stock return.');
                }
                $movement->execute([
                    'organisation_id' => $organisationId,
                    'product_id' => $item['product_id'],
                    'branch_id' => $sale['branch_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'reference_id' => $saleId,
                    'notes' => $reason,
                ]);
            }

            $update = $this->pdo->prepare(
                "UPDATE sales SET status = 'refunded'
                 WHERE id = :sale_id AND organisation_id = :organisation_id AND status = 'posted'"
            );
            $update->execute(['sale_id' => $saleId, 'organisation_id' => $organisationId]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'sale_not_refundable', 'The sale status changed. Refresh and try again.');
            }
            $this->audit->record($organisationId, $userId, 'sale.refunded', 'sale', [
                'sale_id' => $saleId,
                'amount' => $sale['total'],
                'reason' => $reason,
            ]);
            $this->outbox?->enqueue($organisationId, 'sale', $saleId, 'SaleCancelled', ['sale_id' => $saleId]);
            $this->pdo->commit();
            return ['id' => $saleId, 'status' => 'refunded', 'amount' => (float) $sale['total']];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createExpense(int $organisationId, int $userId, array $input): array
    {
        $this->assertOptionalOwned('branches', $input['branch_id'] ?? null, $organisationId, 'branch_id');
        $this->assertOptionalOwned('departments', $input['department_id'] ?? null, $organisationId, 'department_id');
        $this->assertOptionalOwned('expense_categories', $input['category_id'] ?? null, $organisationId, 'category_id');
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO expenses
                    (organisation_id, branch_id, department_id, category_id, expense_date, amount, description, created_at)
                 VALUES
                    (:organisation_id, :branch_id, :department_id, :category_id, :expense_date, :amount, :description, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'organisation_id' => $organisationId,
                'branch_id' => $input['branch_id'] ?? null,
                'department_id' => $input['department_id'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'expense_date' => $input['expense_date'],
                'amount' => $input['amount'],
                'description' => $input['description'],
            ]);
            $expenseId = (int) $this->pdo->lastInsertId();
            $this->audit->record($organisationId, $userId, 'expense.created', 'expense', [
                'expense_id' => $expenseId,
                'amount' => $input['amount'],
            ]);
            $this->outbox?->enqueue($organisationId, 'expense', $expenseId, 'ExpenseCreated', [
                'expense_id' => $expenseId,
                'amount' => $input['amount'],
            ]);
            $this->pdo->commit();
            return ['id' => $expenseId, 'amount' => $input['amount']];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function expenses(int $organisationId, int $limit = 50, int $offset = 0): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.expense_date, e.amount, e.description, ec.name AS category_name,
                    b.name AS branch_name, d.name AS department_name
             FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.category_id AND ec.organisation_id = e.organisation_id
             LEFT JOIN branches b ON b.id = e.branch_id AND b.organisation_id = e.organisation_id
             LEFT JOIN departments d ON d.id = e.department_id AND d.organisation_id = e.organisation_id
             WHERE e.organisation_id = :organisation_id
             ORDER BY e.expense_date DESC, e.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('organisation_id', $organisationId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['expenses' => $statement->fetchAll(), 'limit' => $limit, 'offset' => $offset];
    }

    public function moveStock(int $organisationId, int $userId, int $productId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $productQuery = $this->pdo->prepare(
                'SELECT id, stock_level, unit_cost, branch_id FROM products
                 WHERE id = :product_id AND organisation_id = :organisation_id' . $this->lockSuffix()
            );
            $productQuery->execute(['product_id' => $productId, 'organisation_id' => $organisationId]);
            $product = $productQuery->fetch();
            if ($product === false) {
                throw new ApiException(404, 'product_not_found', 'The product was not found in your organisation.');
            }

            $type = $input['movement_type'];
            $quantity = (int) $input['quantity'];
            $delta = $type === 'adjustment' ? $quantity : abs($quantity);
            if ($type === 'adjustment' && $quantity === 0) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'quantity' => 'An adjustment must change stock.'
                ]);
            }
            if ($type !== 'adjustment' && $quantity < 1) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'quantity' => 'Use a positive quantity for receipts and returns.'
                ]);
            }
            $nextStock = (int) $product['stock_level'] + $delta;
            if ($nextStock < 0) {
                throw new ApiException(409, 'insufficient_stock', 'The movement would make stock negative.');
            }

            $movementCost = (float) ($input['unit_cost'] ?? $product['unit_cost']);
            $newUnitCost = $type === 'receipt' && $nextStock > 0
                ? round((((int) $product['stock_level'] * (float) $product['unit_cost']) + ($quantity * $movementCost)) / $nextStock, 2)
                : (float) $product['unit_cost'];
            $update = $this->pdo->prepare(
                'UPDATE products SET stock_level = :stock_level, unit_cost = :unit_cost
                 WHERE id = :product_id AND organisation_id = :organisation_id'
            );
            $update->execute([
                'stock_level' => $nextStock,
                'unit_cost' => $newUnitCost,
                'product_id' => $productId,
                'organisation_id' => $organisationId,
            ]);
            $branchId = $input['branch_id'] ?? ($product['branch_id'] === null ? null : (int) $product['branch_id']);
            $this->assertOptionalOwned('branches', $branchId, $organisationId, 'branch_id');
            $movement = $this->pdo->prepare(
                'INSERT INTO stock_movements
                    (organisation_id, product_id, branch_id, movement_type, quantity, unit_cost, reference_type, reference_id, notes, created_at)
                 VALUES
                    (:organisation_id, :product_id, :branch_id, :movement_type, :quantity, :unit_cost, :reference_type, NULL, :notes, CURRENT_TIMESTAMP)'
            );
            $movement->execute([
                'organisation_id' => $organisationId,
                'product_id' => $productId,
                'branch_id' => $branchId,
                'movement_type' => $type,
                'quantity' => $delta,
                'unit_cost' => $movementCost,
                'reference_type' => 'manual',
                'notes' => $input['notes'] ?? null,
            ]);
            $movementId = (int) $this->pdo->lastInsertId();
            $this->audit->record($organisationId, $userId, 'inventory.movement_recorded', 'stock_movement', [
                'movement_id' => $movementId,
                'product_id' => $productId,
                'movement_type' => $type,
                'quantity' => $delta,
            ]);
            $this->outbox?->enqueue(
                $organisationId,
                'product',
                $productId,
                $type === 'receipt' || $type === 'return' ? 'StockReceived' : 'StockAdjusted',
                ['movement_id' => $movementId, 'product_id' => $productId, 'movement_type' => $type]
            );
            $this->pdo->commit();
            return ['id' => $movementId, 'product_id' => $productId, 'stock_level' => $nextStock];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function report(int $organisationId, string $from, string $to): array
    {
        $toExclusive = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
        $sales = $this->pdo->prepare(
            "SELECT DATE(s.sale_date) AS period, SUM(s.total) AS revenue,
                    COUNT(*) AS sale_count
             FROM sales s
             WHERE s.organisation_id = :organisation_id AND s.status = 'posted'
               AND s.sale_date >= :from_date AND s.sale_date < :to_exclusive
             GROUP BY DATE(s.sale_date) ORDER BY period"
        );
        $sales->execute([
            'organisation_id' => $organisationId,
            'from_date' => $from,
            'to_exclusive' => $toExclusive,
        ]);
        $expenses = $this->pdo->prepare(
            'SELECT DATE(expense_date) AS period, SUM(amount) AS expenses, COUNT(*) AS expense_count
             FROM expenses
             WHERE organisation_id = :organisation_id AND expense_date >= :from_date
               AND expense_date < :to_exclusive
             GROUP BY DATE(expense_date) ORDER BY period'
        );
        $expenses->execute([
            'organisation_id' => $organisationId,
            'from_date' => $from,
            'to_exclusive' => $toExclusive,
        ]);
        $refunds = $this->pdo->prepare(
            "SELECT DATE(s.sale_date) AS period, SUM(s.total) AS refunds, COUNT(*) AS refund_count
             FROM sales s
             WHERE s.organisation_id = :organisation_id AND s.status = 'refunded'
               AND s.sale_date >= :from_date AND s.sale_date < :to_exclusive
             GROUP BY DATE(s.sale_date) ORDER BY period"
        );
        $refunds->execute([
            'organisation_id' => $organisationId,
            'from_date' => $from,
            'to_exclusive' => $toExclusive,
        ]);
        return [
            'from' => $from,
            'to' => $to,
            'revenue_by_day' => $sales->fetchAll(),
            'expenses_by_day' => $expenses->fetchAll(),
            'refunds_by_day' => $refunds->fetchAll(),
        ];
    }

    private function assertOptionalOwned(string $table, mixed $id, int $organisationId, string $field): void
    {
        if ($id === null) {
            return;
        }
        $this->assertOwned($table, (int) $id, $organisationId, $field);
    }

    private function assertOwned(string $table, int $id, int $organisationId, string $field): void
    {
        $allowedTables = ['branches', 'departments', 'customers', 'product_categories', 'expense_categories'];
        if (!in_array($table, $allowedTables, true)) {
            throw new \LogicException('Unsupported tenant-owned entity table.');
        }
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM {$table} WHERE id = :id AND organisation_id = :organisation_id LIMIT 1"
        );
        $statement->execute(['id' => $id, 'organisation_id' => $organisationId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'The selected record does not belong to your organisation.'
            ]);
        }
    }

    private function lockSuffix(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
