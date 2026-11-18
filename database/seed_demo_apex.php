<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = Config::load(dirname(__DIR__));
$pdo = Database::connect($config);

$orgName = 'Apex Trading Ltd';
$orgRow = $pdo->prepare('SELECT id FROM organisations WHERE slug = :slug LIMIT 1');
$orgRow->execute(['slug' => 'apex-trading-ltd']);
$organisationId = (int) ($orgRow->fetchColumn() ?: 0);

if ($organisationId === 0) {
    $pdo->beginTransaction();
    try {
        $insertOrganisation = $pdo->prepare(
            'INSERT INTO organisations (name, slug, status, created_at, updated_at) VALUES (:name, :slug, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $insertOrganisation->execute(['name' => $orgName, 'slug' => 'apex-trading-ltd', 'status' => 'active']);
        $organisationId = (int) $pdo->lastInsertId();

        $branchInsert = $pdo->prepare('INSERT INTO branches (organisation_id, name, code, created_at) VALUES (:organisation_id, :name, :code, CURRENT_TIMESTAMP)');
        $branchNames = ['North Hub', 'Central Office', 'South Distribution'];
        $branchIds = [];
        foreach ($branchNames as $index => $branchName) {
            $branchInsert->execute(['organisation_id' => $organisationId, 'name' => $branchName, 'code' => 'BR-' . ($index + 1)]);
            $branchIds[] = (int) $pdo->lastInsertId();
        }

        $departmentInsert = $pdo->prepare('INSERT INTO departments (organisation_id, branch_id, name, code, created_at) VALUES (:organisation_id, :branch_id, :name, :code, CURRENT_TIMESTAMP)');
        $departmentMappings = [
            'Sales' => 'SAL',
            'Operations' => 'OPS',
            'Finance' => 'FIN',
            'Procurement' => 'PUR',
        ];
        $departmentIds = [];
        foreach ($departmentMappings as $name => $code) {
            $departmentInsert->execute(['organisation_id' => $organisationId, 'branch_id' => $branchIds[0], 'name' => $name, 'code' => $code]);
            $departmentIds[$name] = (int) $pdo->lastInsertId();
        }

        $categoryInsert = $pdo->prepare('INSERT INTO product_categories (organisation_id, name, created_at) VALUES (:organisation_id, :name, CURRENT_TIMESTAMP)');
        $categories = ['Hardware', 'Accessories', 'Office', 'Services'];
        $categoryIds = [];
        foreach ($categories as $categoryName) {
            $categoryInsert->execute(['organisation_id' => $organisationId, 'name' => $categoryName]);
            $categoryIds[$categoryName] = (int) $pdo->lastInsertId();
        }

        $productInsert = $pdo->prepare(
            'INSERT INTO products (organisation_id, branch_id, category_id, name, sku, unit_cost, selling_price, stock_level, reorder_level, created_at)
             VALUES (:organisation_id, :branch_id, :category_id, :name, :sku, :unit_cost, :selling_price, :stock_level, :reorder_level, CURRENT_TIMESTAMP)'
        );
        $products = [
            ['name' => 'Apex Desk Monitor', 'sku' => 'APX-100', 'unit_cost' => 260, 'selling_price' => 420, 'stock_level' => 120, 'reorder_level' => 45, 'category' => 'Hardware', 'branch' => $branchIds[0]],
            ['name' => 'Summit Dock', 'sku' => 'APX-110', 'unit_cost' => 85, 'selling_price' => 129, 'stock_level' => 80, 'reorder_level' => 30, 'category' => 'Accessories', 'branch' => $branchIds[1]],
            ['name' => 'Atlas Office Chair', 'sku' => 'APX-200', 'unit_cost' => 160, 'selling_price' => 285, 'stock_level' => 42, 'reorder_level' => 24, 'category' => 'Office', 'branch' => $branchIds[2]],
        ];
        $productIds = [];
        foreach ($products as $product) {
            $productInsert->execute([
                'organisation_id' => $organisationId,
                'branch_id' => $product['branch'],
                'category_id' => $categoryIds[$product['category']],
                'name' => $product['name'],
                'sku' => $product['sku'],
                'unit_cost' => number_format($product['unit_cost'], 2, '.', ''),
                'selling_price' => number_format($product['selling_price'], 2, '.', ''),
                'stock_level' => $product['stock_level'],
                'reorder_level' => $product['reorder_level'],
            ]);
            $productIds[$product['sku']] = (int) $pdo->lastInsertId();
        }

        $customerInsert = $pdo->prepare('INSERT INTO customers (organisation_id, name, email, status, created_at) VALUES (:organisation_id, :name, :email, :status, CURRENT_TIMESTAMP)');
        $customers = [
            ['name' => 'Northlake Retail', 'email' => 'finance@northlake.test', 'status' => 'active'],
            ['name' => 'Westline Logistics', 'email' => 'ops@westline.test', 'status' => 'active'],
        ];
        $customerIds = [];
        foreach ($customers as $customer) {
            $customerInsert->execute(['organisation_id' => $organisationId, 'name' => $customer['name'], 'email' => $customer['email'], 'status' => $customer['status']]);
            $customerIds[$customer['name']] = (int) $pdo->lastInsertId();
        }

        $supplierInsert = $pdo->prepare('INSERT INTO suppliers (organisation_id, name, contact_email, status, created_at) VALUES (:organisation_id, :name, :contact_email, :status, CURRENT_TIMESTAMP)');
        $supplierInsert->execute(['organisation_id' => $organisationId, 'name' => 'Mercury Supply Co.', 'contact_email' => 'orders@mercury.test', 'status' => 'active']);
        $supplierId = (int) $pdo->lastInsertId();

        $saleInsert = $pdo->prepare(
            'INSERT INTO sales (organisation_id, branch_id, department_id, customer_id, sale_date, subtotal, discount, tax, total, status, created_at)
             VALUES (:organisation_id, :branch_id, :department_id, :customer_id, :sale_date, :subtotal, :discount, :tax, :total, :status, CURRENT_TIMESTAMP)'
        );
        $saleItemInsert = $pdo->prepare(
            'INSERT INTO sale_items (organisation_id, sale_id, product_id, quantity, unit_price, discount, total)
             VALUES (:organisation_id, :sale_id, :product_id, :quantity, :unit_price, :discount, :total)'
        );

        $saleData = [
            ['branch_id' => $branchIds[0], 'department_id' => $departmentIds['Sales'], 'customer_id' => $customerIds['Northlake Retail'], 'sale_date' => '2026-10-12 09:00:00', 'subtotal' => 4200.00, 'discount' => 120.00, 'tax' => 264.00, 'total' => 4344.00, 'items' => [['sku' => 'APX-100', 'quantity' => 6, 'unit_price' => 420.00, 'discount' => 20.00, 'total' => 2500.00]]],
            ['branch_id' => $branchIds[1], 'department_id' => $departmentIds['Operations'], 'customer_id' => $customerIds['Westline Logistics'], 'sale_date' => '2026-11-01 11:30:00', 'subtotal' => 3200.00, 'discount' => 80.00, 'tax' => 192.00, 'total' => 3312.00, 'items' => [['sku' => 'APX-110', 'quantity' => 18, 'unit_price' => 129.00, 'discount' => 5.00, 'total' => 2320.00]]],
        ];

        $saleIds = [];
        foreach ($saleData as $saleDatum) {
            $saleInsert->execute([
                'organisation_id' => $organisationId,
                'branch_id' => $saleDatum['branch_id'],
                'department_id' => $saleDatum['department_id'],
                'customer_id' => $saleDatum['customer_id'],
                'sale_date' => $saleDatum['sale_date'],
                'subtotal' => number_format($saleDatum['subtotal'], 2, '.', ''),
                'discount' => number_format($saleDatum['discount'], 2, '.', ''),
                'tax' => number_format($saleDatum['tax'], 2, '.', ''),
                'total' => number_format($saleDatum['total'], 2, '.', ''),
                'status' => 'posted',
            ]);
            $saleId = (int) $pdo->lastInsertId();
            $saleIds[] = $saleId;
            foreach ($saleDatum['items'] as $item) {
                $saleItemInsert->execute([
                    'organisation_id' => $organisationId,
                    'sale_id' => $saleId,
                    'product_id' => $productIds[$item['sku']],
                    'quantity' => $item['quantity'],
                    'unit_price' => number_format($item['unit_price'], 2, '.', ''),
                    'discount' => number_format($item['discount'], 2, '.', ''),
                    'total' => number_format($item['total'], 2, '.', ''),
                ]);
            }
        }

        $expenseCategoryInsert = $pdo->prepare('INSERT INTO expense_categories (organisation_id, name, type, created_at) VALUES (:organisation_id, :name, :type, CURRENT_TIMESTAMP)');
        $expenseCategoryInsert->execute(['organisation_id' => $organisationId, 'name' => 'Logistics', 'type' => 'operating']);
        $expenseCategoryId = (int) $pdo->lastInsertId();

        $expenseInsert = $pdo->prepare('INSERT INTO expenses (organisation_id, branch_id, department_id, category_id, expense_date, amount, description, created_at)
            VALUES (:organisation_id, :branch_id, :department_id, :category_id, :expense_date, :amount, :description, CURRENT_TIMESTAMP)');
        $expenseInsert->execute([
            'organisation_id' => $organisationId,
            'branch_id' => $branchIds[0],
            'department_id' => $departmentIds['Operations'],
            'category_id' => $expenseCategoryId,
            'expense_date' => '2026-11-03 12:00:00',
            'amount' => '1200.00',
            'description' => 'Regional delivery and warehousing',
        ]);

        $invoiceInsert = $pdo->prepare(
            'INSERT INTO invoices (organisation_id, customer_id, branch_id, invoice_number, issue_date, due_date, total, paid_amount, status, created_at)
             VALUES (:organisation_id, :customer_id, :branch_id, :invoice_number, :issue_date, :due_date, :total, :paid_amount, :status, CURRENT_TIMESTAMP)'
        );
        $invoiceInsert->execute([
            'organisation_id' => $organisationId,
            'customer_id' => $customerIds['Northlake Retail'],
            'branch_id' => $branchIds[0],
            'invoice_number' => 'INV-1001',
            'issue_date' => '2026-10-20 00:00:00',
            'due_date' => '2026-11-20 00:00:00',
            'total' => '4344.00',
            'paid_amount' => '2500.00',
            'status' => 'partial',
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $paymentInsert = $pdo->prepare('INSERT INTO payments (organisation_id, invoice_id, customer_id, payment_date, amount, method, reference, created_at) VALUES (:organisation_id, :invoice_id, :customer_id, :payment_date, :amount, :method, :reference, CURRENT_TIMESTAMP)');
        $paymentInsert->execute([
            'organisation_id' => $organisationId,
            'invoice_id' => $invoiceId,
            'customer_id' => $customerIds['Northlake Retail'],
            'payment_date' => '2026-11-05 00:00:00',
            'amount' => '2500.00',
            'method' => 'bank_transfer',
            'reference' => 'REF-11209',
        ]);

        $orderInsert = $pdo->prepare(
            'INSERT INTO purchase_orders (organisation_id, supplier_id, branch_id, order_date, expected_date, status, total, created_at)
             VALUES (:organisation_id, :supplier_id, :branch_id, :order_date, :expected_date, :status, :total, CURRENT_TIMESTAMP)'
        );
        $orderInsert->execute([
            'organisation_id' => $organisationId,
            'supplier_id' => $supplierId,
            'branch_id' => $branchIds[2],
            'order_date' => '2026-11-08 00:00:00',
            'expected_date' => '2026-11-16 00:00:00',
            'status' => 'submitted',
            'total' => '6300.00',
        ]);
        $orderId = (int) $pdo->lastInsertId();
        $poItemInsert = $pdo->prepare(
            'INSERT INTO purchase_order_items (organisation_id, purchase_order_id, product_id, quantity, unit_cost, total)
             VALUES (:organisation_id, :purchase_order_id, :product_id, :quantity, :unit_cost, :total)'
        );
        foreach ([['sku' => 'APX-100', 'quantity' => 15, 'unit_cost' => 260.00], ['sku' => 'APX-200', 'quantity' => 10, 'unit_cost' => 160.00]] as $line) {
            $poItemInsert->execute([
                'organisation_id' => $organisationId,
                'purchase_order_id' => $orderId,
                'product_id' => $productIds[$line['sku']],
                'quantity' => $line['quantity'],
                'unit_cost' => number_format($line['unit_cost'], 2, '.', ''),
                'total' => number_format($line['quantity'] * $line['unit_cost'], 2, '.', ''),
            ]);
        }

        $stockMovementInsert = $pdo->prepare('INSERT INTO stock_movements (organisation_id, product_id, branch_id, movement_type, quantity, unit_cost, reference_type, reference_id, notes, created_at) VALUES (:organisation_id, :product_id, :branch_id, :movement_type, :quantity, :unit_cost, :reference_type, :reference_id, :notes, CURRENT_TIMESTAMP)');
        foreach ($products as $product) {
            $stockMovementInsert->execute([
                'organisation_id' => $organisationId,
                'product_id' => $productIds[$product['sku']],
                'branch_id' => $product['branch'],
                'movement_type' => 'adjustment',
                'quantity' => 18,
                'unit_cost' => number_format($product['unit_cost'], 2, '.', ''),
                'reference_type' => 'seed',
                'reference_id' => 1,
                'notes' => 'Initial operational stock alignment',
            ]);
        }

        $riskInsert = $pdo->prepare('INSERT INTO risk_alerts (organisation_id, category, severity, detected_at, business_area, evidence, explanation, suggested_action, reviewer_user_id, status, resolution_notes, created_at)
            VALUES (:organisation_id, :category, :severity, :detected_at, :business_area, :evidence, :explanation, :suggested_action, :reviewer_user_id, :status, :resolution_notes, CURRENT_TIMESTAMP)');
        $riskInsert->execute([
            'organisation_id' => $organisationId,
            'category' => 'Unusual expenditure',
            'severity' => 'high',
            'detected_at' => '2026-11-09 00:00:00',
            'business_area' => 'Finance',
            'evidence' => '{"variance_percent": 18.2}',
            'explanation' => 'Logistics spend exceeded the rolling 90-day baseline in Northern operations.',
            'suggested_action' => 'Review supplier and freight variance with the finance team.',
            'reviewer_user_id' => null,
            'status' => 'open',
            'resolution_notes' => null,
        ]);

        $forecastInsert = $pdo->prepare('INSERT INTO forecasts (organisation_id, forecast_type, forecast_for, methodology, model_version, data_coverage, confidence_interval, assumptions, generated_at) VALUES (:organisation_id, :forecast_type, :forecast_for, :methodology, :model_version, :data_coverage, :confidence_interval, :assumptions, CURRENT_TIMESTAMP)');
        $forecastInsert->execute([
            'organisation_id' => $organisationId,
            'forecast_type' => 'revenue',
            'forecast_for' => '2026-12-31 00:00:00',
            'methodology' => 'Weighted moving average with seasonal uplift',
            'model_version' => 'v1.3',
            'data_coverage' => '92.00',
            'confidence_interval' => '5.50',
            'assumptions' => '{"lag": 0.07, "seasonality": "quarterly"}',
        ]);
        $forecastId = (int) $pdo->lastInsertId();

        $forecastResultInsert = $pdo->prepare(
            'INSERT INTO forecast_results (organisation_id, forecast_id, period_label, actual_value, projected_value, created_at)
             VALUES (:organisation_id, :forecast_id, :period_label, :actual_value, :projected_value, CURRENT_TIMESTAMP)'
        );
        $forecastResultInsert->execute([
            'organisation_id' => $organisationId,
            'forecast_id' => $forecastId,
            'period_label' => 'Q4 2026',
            'actual_value' => '140000.00',
            'projected_value' => '153500.00'
        ]);

        $scenarioInsert = $pdo->prepare('INSERT INTO decision_scenarios (organisation_id, name, description, status, created_by_user_id, created_at) VALUES (:organisation_id, :name, :description, :status, :created_by_user_id, CURRENT_TIMESTAMP)');
        $scenarioInsert->execute([
            'organisation_id' => $organisationId,
            'name' => 'Expansion with price uplift',
            'description' => 'Increase premium hardware pricing while holding operating costs steady.',
            'status' => 'active',
            'created_by_user_id' => null,
        ]);

        $pdo->commit();
        fwrite(STDOUT, "Demo organisation Apex Trading Ltd created successfully.\n");
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
} else {
    fwrite(STDOUT, "Apex Trading Ltd already exists.\n");
}
