<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Http\ApiException;
use Vantiq\Repository\AuditRepository;
use Vantiq\Repository\EnterpriseWorkflowRepository;
use Vantiq\Repository\LedgerRepository;

$pdo = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER, user_id INTEGER, action_type TEXT NOT NULL,
  entity_name TEXT NOT NULL, details TEXT, created_at TEXT
);
CREATE TABLE branches (id INTEGER PRIMARY KEY, organisation_id INTEGER NOT NULL, name TEXT);
CREATE TABLE customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, name TEXT NOT NULL,
  email TEXT, status TEXT NOT NULL DEFAULT 'active', created_at TEXT
);
CREATE TABLE suppliers (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, name TEXT NOT NULL,
  contact_email TEXT, status TEXT NOT NULL, created_at TEXT
);
CREATE TABLE products (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, branch_id INTEGER, name TEXT NOT NULL,
  selling_price DECIMAL(18,2) NOT NULL, unit_cost DECIMAL(18,2) NOT NULL, stock_level INTEGER NOT NULL
);
CREATE TABLE invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, customer_id INTEGER NOT NULL,
  branch_id INTEGER, invoice_number TEXT NOT NULL, issue_date TEXT NOT NULL, due_date TEXT NOT NULL,
  total DECIMAL(18,2) NOT NULL, tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(18,2) NOT NULL, status TEXT NOT NULL, sale_id INTEGER, created_at TEXT
);
CREATE TABLE invoice_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL, product_id INTEGER, description TEXT NOT NULL,
  quantity INTEGER NOT NULL, unit_price DECIMAL(18,2) NOT NULL, unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
  total DECIMAL(18,2) NOT NULL
);
CREATE TABLE payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, invoice_id INTEGER, customer_id INTEGER,
  payment_date TEXT NOT NULL, amount DECIMAL(18,2) NOT NULL, method TEXT NOT NULL, reference TEXT, created_at TEXT
);
CREATE TABLE sales (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, branch_id INTEGER, customer_id INTEGER,
  sale_date TEXT NOT NULL, subtotal DECIMAL(18,2), discount DECIMAL(18,2), tax DECIMAL(18,2),
  total DECIMAL(18,2), status TEXT NOT NULL, created_at TEXT
);
CREATE TABLE sale_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT, sale_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL, unit_price DECIMAL(18,2), unit_cost DECIMAL(18,2), discount DECIMAL(18,2), total DECIMAL(18,2)
);
CREATE TABLE credit_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, invoice_id INTEGER NOT NULL,
  credit_number TEXT NOT NULL, reason TEXT NOT NULL, subtotal DECIMAL(18,2), tax_amount DECIMAL(18,2),
  total DECIMAL(18,2), stock_returned INTEGER, created_by_user_id INTEGER, created_at TEXT
);
CREATE TABLE credit_note_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT, credit_note_id INTEGER NOT NULL, invoice_item_id INTEGER NOT NULL,
  product_id INTEGER, quantity INTEGER NOT NULL, returned_quantity INTEGER NOT NULL, unit_price DECIMAL(18,2),
  unit_cost DECIMAL(18,2), subtotal DECIMAL(18,2), tax_amount DECIMAL(18,2), total DECIMAL(18,2)
);
CREATE TABLE invoice_refunds (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, invoice_id INTEGER NOT NULL,
  credit_note_id INTEGER NOT NULL, amount DECIMAL(18,2), method TEXT NOT NULL, reference TEXT, refunded_at TEXT,
  created_by_user_id INTEGER
);
CREATE TABLE purchase_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, supplier_id INTEGER NOT NULL,
  branch_id INTEGER, order_date TEXT NOT NULL, expected_date TEXT, status TEXT NOT NULL, total DECIMAL(18,2), created_at TEXT
);
CREATE TABLE purchase_order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT, purchase_order_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL, unit_cost DECIMAL(18,2) NOT NULL, total DECIMAL(18,2) NOT NULL
);
CREATE TABLE stock_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT, organisation_id INTEGER NOT NULL, product_id INTEGER NOT NULL, branch_id INTEGER,
  movement_type TEXT NOT NULL, quantity INTEGER NOT NULL, unit_cost DECIMAL(18,2), reference_type TEXT,
  reference_id INTEGER, notes TEXT, created_at TEXT
);
INSERT INTO customers (id, organisation_id, name, status) VALUES (1, 1, 'Local customer', 'active'), (2, 2, 'Foreign customer', 'active');
INSERT INTO suppliers (id, organisation_id, name, status) VALUES (1, 1, 'Local supplier', 'active'), (2, 2, 'Foreign supplier', 'active');
INSERT INTO products (id, organisation_id, name, selling_price, unit_cost, stock_level)
VALUES (1, 1, 'Widget', 12.50, 4.00, 2), (2, 2, 'Foreign widget', 20.00, 8.00, 4);
SQL);

$workflow = new EnterpriseWorkflowRepository($pdo, new AuditRepository($pdo));
$ledger = new LedgerRepository($pdo, new AuditRepository($pdo));
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

$invoiceId = null;
$test('Invoice uses tenant product pricing and creates traceable invoice items', static function () use ($workflow, $pdo, $assert, &$invoiceId): void {
    $invoice = $workflow->createInvoice(1, 10, [
        'customer_id' => 1,
        'issue_date' => '2025-01-01 00:00:00',
        'due_date' => '2027-01-31 00:00:00',
        'tax_amount' => '2.50',
        'items' => [[
            'product_id' => 1,
            'description' => '',
            'quantity' => 2,
            'unit_price' => '999.00',
        ]],
    ]);
    $invoiceId = $invoice['id'];
    $item = $pdo->query('SELECT description, quantity, unit_price, total FROM invoice_items WHERE invoice_id = ' . (int) $invoiceId)->fetch();
    $assert((float) $invoice['total'] === 27.5, 'Invoice should use server prices and include the entered tax.');
    $assert($item['description'] === 'Widget' && (float) $item['unit_price'] === 12.5 && (float) $item['total'] === 25.0, 'Invoice item snapshot is invalid.');
    $assert((int) $pdo->query('SELECT stock_level FROM products WHERE id = 1')->fetchColumn() === 0, 'Invoice issue must deduct the invoiced stock quantity.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 1, 'Invoice revenue was not posted to the sales ledger.');
    $assert((int) $pdo->query('SELECT sale_id FROM invoices WHERE id = ' . (int) $invoiceId)->fetchColumn() === 1, 'Invoice-to-sale link is missing.');
});

$test('Payments support partial settlement and reject overpayment', static function () use ($workflow, $pdo, $assert, &$invoiceId): void {
    $partial = $workflow->recordPayment(1, 10, (int) $invoiceId, [
        'payment_date' => '2025-01-10 00:00:00', 'amount' => '10.00', 'method' => 'bank_transfer', 'reference' => null,
    ]);
    $assert($partial['invoice_status'] === 'partial' && $partial['balance_due'] === 17.5, 'Partial payment balance or status is incorrect.');
    $settled = $workflow->recordPayment(1, 10, (int) $invoiceId, [
        'payment_date' => '2025-01-11 00:00:00', 'amount' => '17.50', 'method' => 'cash', 'reference' => null,
    ]);
    $assert($settled['invoice_status'] === 'paid' && $settled['balance_due'] === 0.0, 'Full settlement did not close the invoice.');
    try {
        $workflow->recordPayment(1, 10, (int) $invoiceId, [
            'payment_date' => '2025-01-12 00:00:00', 'amount' => '0.01', 'method' => 'cash', 'reference' => null,
        ]);
        throw new RuntimeException('An overpayment should fail.');
    } catch (ApiException $exception) {
        $assert($exception->status === 409, 'Overpayment must return a conflict.');
    }
    $assert((float) $pdo->query('SELECT paid_amount FROM invoices WHERE id = ' . (int) $invoiceId)->fetchColumn() === 27.5, 'Rejected payment changed the invoice balance.');
});

$test('Partial and full credits refund settled invoice amounts with tax and stock traceability', static function () use ($workflow, $pdo, $assert, &$invoiceId): void {
    $detail = $workflow->invoiceDetail(1, (int) $invoiceId);
    $itemId = (int) $detail['items'][0]['id'];
    $partial = $workflow->createCreditNote(1, 10, (int) $invoiceId, [
        'reason' => 'One item returned.',
        'refund_method' => 'bank_transfer',
        'refund_reference' => 'R-100',
        'items' => [['invoice_item_id' => $itemId, 'quantity' => 1, 'return_to_stock' => true]],
    ]);
    $assert($partial['total'] === 13.75 && $partial['tax_amount'] === 1.25 && $partial['refunded'] === true, 'Partial credit did not prorate tax or refund the paid amount correctly.');
    $assert((int) $pdo->query('SELECT stock_level FROM products WHERE id = 1')->fetchColumn() === 1, 'Returned item was not restocked.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reference_type = 'invoice_credit'")->fetchColumn() === 1, 'Stock return lacks a traceable credit movement.');

    $full = $workflow->createCreditNote(1, 10, (int) $invoiceId, [
        'reason' => 'Remaining item returned.',
        'refund_method' => 'cash',
        'refund_reference' => null,
        'items' => [['invoice_item_id' => $itemId, 'quantity' => 1, 'return_to_stock' => true]],
    ]);
    $assert($full['total'] === 13.75 && $full['balance_due'] === 0.0 && $full['invoice_status'] === 'paid', 'Final credit did not reconcile invoice total and net paid.');
    $assert((int) $pdo->query('SELECT stock_level FROM products WHERE id = 1')->fetchColumn() === 2, 'Second return did not update stock.');
    $assert((float) $pdo->query('SELECT SUM(amount) FROM invoice_refunds')->fetchColumn() === 27.5, 'Cash refunds do not reconcile to total issued credits.');
    $assert((float) $pdo->query('SELECT paid_amount FROM invoices WHERE id = ' . (int) $invoiceId)->fetchColumn() === 0.0, 'Invoice net paid amount is not reconciled after refunds.');
    try {
        $workflow->createCreditNote(1, 10, (int) $invoiceId, [
            'reason' => 'Duplicate credit.',
            'refund_method' => 'cash',
            'refund_reference' => null,
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1, 'return_to_stock' => false]],
        ]);
        throw new RuntimeException('An over-credit should fail.');
    } catch (ApiException $exception) {
        $assert($exception->status === 409, 'Duplicate/excess credit did not return a conflict.');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM credit_notes')->fetchColumn() === 2, 'Rejected credit created a note.');
});

$test('Insufficient invoice stock rolls back invoice and revenue postings', static function () use ($workflow, $pdo, $assert): void {
    try {
        $workflow->createInvoice(1, 10, [
            'customer_id' => 1,
            'issue_date' => '2025-01-02 00:00:00',
            'due_date' => '2027-01-31 00:00:00',
            'items' => [[
                'product_id' => 1,
                'description' => '',
                'quantity' => 100,
                'unit_price' => '12.50',
            ]],
        ]);
        throw new RuntimeException('Issuing an invoice without available stock should fail.');
    } catch (ApiException $exception) {
        $assert($exception->status === 409 && $exception->errorCode === 'insufficient_stock', 'Insufficient stock did not return a conflict.');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === 1, 'Failed invoice header was not rolled back.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 1, 'Failed invoice sale was not rolled back.');
    $assert((int) $pdo->query('SELECT stock_level FROM products WHERE id = 1')->fetchColumn() === 0, 'Failed invoice changed inventory.');
});

$test('Foreign tenant invoice and product references are rejected', static function () use ($workflow, $assert, &$invoiceId): void {
    try {
        $workflow->createInvoice(1, 10, [
            'customer_id' => 2,
            'issue_date' => '2025-01-01 00:00:00',
            'due_date' => '2025-01-31 00:00:00',
            'items' => [['product_id' => 1, 'description' => '', 'quantity' => 1, 'unit_price' => '12.50']],
        ]);
        throw new RuntimeException('Foreign customers must not be used.');
    } catch (ApiException $exception) {
        $assert($exception->status === 422, 'Foreign customer reference was not rejected.');
    }
    try {
        $workflow->recordPayment(2, 20, (int) $invoiceId, [
            'payment_date' => '2025-01-12 00:00:00', 'amount' => '1.00', 'method' => 'cash', 'reference' => null,
        ]);
        throw new RuntimeException('Foreign invoice IDs must not be visible.');
    } catch (ApiException $exception) {
        $assert($exception->status === 404, 'Foreign invoice should be hidden as not found.');
    }
});

$test('Invoice sales cannot use point-of-sale refunds', static function () use ($ledger, $assert, &$invoiceId): void {
    try {
        $ledger->refundSale(1, 10, 1, 'Incorrect refund path');
        throw new RuntimeException('An invoice-linked sale must not be refunded outside invoice accounting.');
    } catch (ApiException $exception) {
        $assert($exception->status === 409 && $exception->errorCode === 'invoice_credit_required', 'Invoice-linked sale was not protected from inconsistent refunding.');
    }
});

$test('Purchase-order receipt updates stock, weighted cost, and movement ledger once', static function () use ($workflow, $pdo, $assert): void {
    $order = $workflow->createPurchaseOrder(1, 10, [
        'supplier_id' => 1,
        'branch_id' => null,
        'order_date' => '2025-02-01 00:00:00',
        'expected_date' => null,
        'items' => [
            ['product_id' => 1, 'quantity' => 2, 'unit_cost' => '5.00'],
            ['product_id' => 1, 'quantity' => 4, 'unit_cost' => '6.00'],
        ],
    ]);
    $workflow->submitPurchaseOrder(1, 10, $order['id']);
    $received = $workflow->receivePurchaseOrder(1, 10, $order['id']);
    $product = $pdo->query('SELECT stock_level, unit_cost FROM products WHERE id = 1')->fetch();
    $movement = $pdo->query("SELECT SUM(quantity) AS quantity, SUM(quantity * unit_cost) AS total_cost, COUNT(*) AS movement_count
      FROM stock_movements WHERE reference_type = 'purchase_order'")->fetch();
    $assert($received['status'] === 'received' && $received['item_count'] === 1, 'Purchase order was not received as a single aggregated product movement.');
    $assert((int) $product['stock_level'] === 8 && (float) $product['unit_cost'] === 5.25, 'Receipt stock or weighted-average cost is incorrect.');
    $assert((int) $movement['quantity'] === 6 && (float) $movement['total_cost'] === 34.0 && (int) $movement['movement_count'] === 2, 'Receipt movements do not preserve the source order-line quantities and costs.');
    try {
        $workflow->receivePurchaseOrder(1, 10, $order['id']);
        throw new RuntimeException('A purchase order must not be received twice.');
    } catch (ApiException $exception) {
        $assert($exception->status === 409, 'Duplicate receipt should return a conflict.');
    }
    $assert((int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reference_type = 'purchase_order'")->fetchColumn() === 2, 'Duplicate receipt created additional stock movements.');
});

$test('Purchase-order creation rejects foreign supplier and product IDs', static function () use ($workflow, $pdo, $assert): void {
    foreach ([
        ['supplier_id' => 2, 'product_id' => 1],
        ['supplier_id' => 1, 'product_id' => 2],
    ] as $case) {
        try {
            $workflow->createPurchaseOrder(1, 10, [
                'supplier_id' => $case['supplier_id'],
                'branch_id' => null,
                'order_date' => '2025-02-01 00:00:00',
                'expected_date' => null,
                'items' => [['product_id' => $case['product_id'], 'quantity' => 1, 'unit_cost' => '1.00']],
            ]);
            throw new RuntimeException('Foreign tenant reference should fail.');
        } catch (ApiException $exception) {
            $assert(in_array($exception->status, [404, 422], true), 'Foreign reference did not return a validation/not-found response.');
        }
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn() === 1, 'Rejected purchase orders were persisted.');
});

$failed = 0;
foreach ($tests as [$name, $passed, $message]) {
    fwrite($passed ? STDOUT : STDERR, ($passed ? 'PASS ' : 'FAIL ') . $name . ($passed ? '' : ': ' . $message) . PHP_EOL);
    if (!$passed) {
        $failed++;
    }
}
fwrite(STDOUT, count($tests) . ' workflow tests, ' . $failed . " failures\n");
exit($failed === 0 ? 0 : 1);
