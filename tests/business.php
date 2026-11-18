<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Http\ApiException;
use Vantiq\Repository\AuditRepository;
use Vantiq\Repository\LedgerRepository;

$pdo = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE branches (id INTEGER PRIMARY KEY, organisation_id INTEGER NOT NULL);
CREATE TABLE departments (id INTEGER PRIMARY KEY, organisation_id INTEGER NOT NULL);
CREATE TABLE customers (id INTEGER PRIMARY KEY, organisation_id INTEGER NOT NULL);
CREATE TABLE product_categories (id INTEGER PRIMARY KEY, organisation_id INTEGER NOT NULL);
CREATE TABLE expense_categories (id INTEGER PRIMARY KEY, organisation_id INTEGER NOT NULL);
CREATE TABLE products (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, branch_id INTEGER, category_id INTEGER,
  name TEXT NOT NULL, sku TEXT NOT NULL, unit_cost DECIMAL(18,2) NOT NULL, selling_price DECIMAL(18,2) NOT NULL,
  stock_level INTEGER NOT NULL DEFAULT 0, reorder_level INTEGER NOT NULL DEFAULT 0, created_at TEXT,
  UNIQUE (organisation_id, sku)
);
CREATE TABLE sales (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, branch_id INTEGER, department_id INTEGER,
  customer_id INTEGER, sale_date TEXT NOT NULL, subtotal DECIMAL(18,2), discount DECIMAL(18,2), tax DECIMAL(18,2),
  total DECIMAL(18,2), status TEXT NOT NULL, created_at TEXT
);
CREATE TABLE sale_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, sale_id INTEGER NOT NULL, product_id INTEGER NOT NULL, quantity INTEGER NOT NULL,
  unit_price DECIMAL(18,2) NOT NULL, unit_cost DECIMAL(18,2) NOT NULL, discount DECIMAL(18,2), total DECIMAL(18,2)
);
CREATE TABLE stock_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, product_id INTEGER NOT NULL, branch_id INTEGER,
  movement_type TEXT NOT NULL, quantity INTEGER NOT NULL, unit_cost DECIMAL(18,2), reference_type TEXT, reference_id INTEGER,
  notes TEXT, created_at TEXT
);
CREATE TABLE expenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, branch_id INTEGER, department_id INTEGER,
  category_id INTEGER, expense_date TEXT NOT NULL, amount DECIMAL(18,2) NOT NULL, description TEXT, created_at TEXT
);
CREATE TABLE invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, sale_id INTEGER, invoice_number TEXT
);
CREATE TABLE audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER, user_id INTEGER, action_type TEXT NOT NULL,
  entity_name TEXT NOT NULL, details TEXT, created_at TEXT
);
SQL);

$audit = new AuditRepository($pdo);
$ledger = new LedgerRepository($pdo, $audit);
$tests = [];
$test = static function (string $name, callable $run) use (&$tests): void {
    try {
        $run();
        $tests[] = [$name, true, ''];
    } catch (Throwable $exception) {
        $tests[] = [$name, false, $exception::class . ': ' . $exception->getMessage()];
    }
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$productId = null;
$test('Product creation starts at zero stock and records an audit event', static function () use ($ledger, $pdo, $assert, &$productId): void {
    $result = $ledger->createProduct(1, 10, [
        'name' => 'Monitor',
        'sku' => 'MON-01',
        'unit_cost' => '8.00',
        'selling_price' => '20.00',
        'reorder_level' => 3,
    ]);
    $productId = $result['id'];
    $assert($result['stock_level'] === 0, 'New products must start with zero stock.');
    $assert((int) $pdo->query('SELECT stock_level FROM products WHERE id = ' . (int) $productId)->fetchColumn() === 0, 'Unexpected stored stock.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action_type = "product.created"')->fetchColumn() === 1, 'Product creation audit event was not recorded.');
});

$test('Receipt, sale, stock deduction, and cost snapshot are consistent', static function () use ($ledger, $pdo, $assert, &$productId): void {
    $receipt = $ledger->moveStock(1, 10, (int) $productId, [
        'movement_type' => 'receipt',
        'quantity' => 10,
        'unit_cost' => '8.00',
        'notes' => 'Initial receipt',
    ]);
    $assert($receipt['stock_level'] === 10, 'Receipt did not increase stock.');
    $sale = $ledger->createSale(1, 10, [
        'sale_date' => '2025-01-15 00:00:00',
        'discount' => '2.00',
        'tax' => '2.50',
        'items' => [['product_id' => (int) $productId, 'quantity' => 2]],
    ]);
    $assert($sale['subtotal'] === 40.0 && $sale['total'] === 40.5, 'Sale total was not calculated from the server product price.');
    $assert((int) $pdo->query('SELECT stock_level FROM products WHERE id = ' . (int) $productId)->fetchColumn() === 8, 'Sale did not deduct stock.');
    $item = $pdo->query('SELECT unit_cost, quantity FROM sale_items WHERE sale_id = ' . (int) $sale['id'])->fetch();
    $assert((float) $item['unit_cost'] === 8.0 && (int) $item['quantity'] === 2, 'The sale cost snapshot is incorrect.');
    $movement = $pdo->query('SELECT quantity, movement_type FROM stock_movements WHERE reference_id = ' . (int) $sale['id'])->fetch();
    $assert((int) $movement['quantity'] === -2 && $movement['movement_type'] === 'sale', 'Sale movement was not traceable.');
});

$test('Failed overdrawn sale rolls back without mutating inventory', static function () use ($ledger, $pdo, $assert, &$productId): void {
    try {
        $ledger->createSale(1, 10, [
            'sale_date' => '2025-01-16 00:00:00',
            'discount' => 0,
            'tax' => 0,
            'items' => [['product_id' => (int) $productId, 'quantity' => 9]],
        ]);
        throw new RuntimeException('An overdrawn sale should fail.');
    } catch (ApiException $exception) {
        $assert($exception->status === 409, 'Overdrawn sale did not return conflict.');
    }
    $assert((int) $pdo->query('SELECT stock_level FROM products WHERE id = ' . (int) $productId)->fetchColumn() === 8, 'Failed sale changed stock.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 1, 'Failed sale remained in the ledger.');
});

$test('Tenant isolation rejects foreign products and branch references', static function () use ($ledger, $pdo, $assert): void {
    $foreign = $ledger->createProduct(2, 20, [
        'name' => 'Foreign product',
        'sku' => 'FOREIGN-01',
        'unit_cost' => '1.00',
        'selling_price' => '2.00',
        'reorder_level' => 0,
    ]);
    try {
        $ledger->createSale(1, 10, [
            'sale_date' => '2025-01-17 00:00:00',
            'discount' => 0,
            'tax' => 0,
            'items' => [['product_id' => $foreign['id'], 'quantity' => 1]],
        ]);
        throw new RuntimeException('Cross-tenant product use should fail.');
    } catch (ApiException $exception) {
        $assert($exception->status === 404, 'Cross-tenant access should be hidden as not found.');
    }
    $pdo->exec('INSERT INTO branches (id, organisation_id) VALUES (9, 2)');
    try {
        $ledger->createProduct(1, 10, [
            'name' => 'Invalid branch product',
            'sku' => 'BAD-BRANCH',
            'unit_cost' => '1.00',
            'selling_price' => '2.00',
            'reorder_level' => 0,
            'branch_id' => 9,
        ]);
        throw new RuntimeException('Cross-tenant branch assignment should fail.');
    } catch (ApiException $exception) {
        $assert($exception->status === 422, 'Cross-tenant branch assignment was not rejected.');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 1, 'Rejected cross-tenant sale was persisted.');
});

$test('Expense entries and date-filtered reports use actual ledger rows', static function () use ($ledger, $assert): void {
    $expense = $ledger->createExpense(1, 10, [
        'expense_date' => '2025-01-16 00:00:00',
        'amount' => '15.25',
        'description' => 'Office supplies',
    ]);
    $assert($expense['amount'] === '15.25', 'Expense amount was not preserved.');
    $report = $ledger->report(1, '2025-01-01', '2025-01-31');
    $assert(count($report['revenue_by_day']) === 1, 'Report did not include the posted sale.');
    $assert((float) $report['revenue_by_day'][0]['revenue'] === 40.5, 'Report revenue does not match the posted transaction.');
    $assert(count($report['expenses_by_day']) === 1 && (float) $report['expenses_by_day'][0]['expenses'] === 15.25, 'Report expense total is incorrect.');
});

$test('Refund reverses revenue and restores stock exactly once', static function () use ($ledger, $pdo, $assert, &$productId): void {
    $result = $ledger->refundSale(1, 10, 1, 'Customer returned the item.');
    $assert($result['status'] === 'refunded' && $result['amount'] === 40.5, 'Refund result does not match the sale.');
    $assert((int) $pdo->query('SELECT stock_level FROM products WHERE id = ' . (int) $productId)->fetchColumn() === 10, 'Refund did not restore sold stock.');
    $report = $ledger->report(1, '2025-01-01', '2025-01-31');
    $assert($report['revenue_by_day'] === [], 'Refunded sales must not remain in posted revenue.');
    $assert(count($report['refunds_by_day']) === 1 && (float) $report['refunds_by_day'][0]['refunds'] === 40.5, 'Refund was not reported separately.');
    try {
        $ledger->refundSale(1, 10, 1, 'Duplicate refund attempt.');
        throw new RuntimeException('A sale must not be refunded twice.');
    } catch (ApiException $exception) {
        $assert($exception->status === 409, 'Duplicate refund should return conflict.');
    }
});

$failed = 0;
foreach ($tests as [$name, $passed, $message]) {
    fwrite($passed ? STDOUT : STDERR, ($passed ? 'PASS ' : 'FAIL ') . $name . ($passed ? '' : ': ' . $message) . PHP_EOL);
    if (!$passed) {
        $failed++;
    }
}
fwrite(STDOUT, count($tests) . ' business tests, ' . $failed . " failures\n");
exit($failed === 0 ? 0 : 1);
