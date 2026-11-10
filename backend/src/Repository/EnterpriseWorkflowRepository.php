<?php
declare(strict_types=1);

namespace Vantiq\Repository;

use PDO;
use Vantiq\Http\ApiException;

final class EnterpriseWorkflowRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditRepository $audit
    ) {
    }

    public function invoices(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.sale_id, i.invoice_number, i.issue_date, i.due_date, i.total, i.tax_amount, i.paid_amount, i.status,
                    c.name AS customer_name,
                    COALESCE((SELECT SUM(cn.total) FROM credit_notes cn
                              WHERE cn.invoice_id = i.id AND cn.organisation_id = i.organisation_id), 0) AS credited_amount,
                    COALESCE((SELECT SUM(ir.amount) FROM invoice_refunds ir
                              WHERE ir.invoice_id = i.id AND ir.organisation_id = i.organisation_id), 0) AS refunded_amount,
                    CASE WHEN i.total
                        - COALESCE((SELECT SUM(cn.total) FROM credit_notes cn
                                    WHERE cn.invoice_id = i.id AND cn.organisation_id = i.organisation_id), 0)
                        - COALESCE((SELECT SUM(p.amount) FROM payments p
                                    WHERE p.invoice_id = i.id AND p.organisation_id = i.organisation_id), 0)
                        + COALESCE((SELECT SUM(ir.amount) FROM invoice_refunds ir
                                    WHERE ir.invoice_id = i.id AND ir.organisation_id = i.organisation_id), 0) > 0
                    THEN i.total
                        - COALESCE((SELECT SUM(cn.total) FROM credit_notes cn
                                    WHERE cn.invoice_id = i.id AND cn.organisation_id = i.organisation_id), 0)
                        - COALESCE((SELECT SUM(p.amount) FROM payments p
                                    WHERE p.invoice_id = i.id AND p.organisation_id = i.organisation_id), 0)
                        + COALESCE((SELECT SUM(ir.amount) FROM invoice_refunds ir
                                    WHERE ir.invoice_id = i.id AND ir.organisation_id = i.organisation_id), 0)
                    ELSE 0 END AS balance_due
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id AND c.organisation_id = i.organisation_id
             WHERE i.organisation_id = :organisation_id
             ORDER BY i.issue_date DESC, i.id DESC LIMIT 100'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return ['invoices' => $statement->fetchAll()];
    }

    public function invoiceDetail(int $organisationId, int $invoiceId): array
    {
        $invoiceQuery = $this->pdo->prepare(
            'SELECT i.id, i.sale_id, i.invoice_number, i.issue_date, i.due_date, i.total, i.tax_amount,
                    i.paid_amount, i.status, c.name AS customer_name,
                    COALESCE((SELECT SUM(cn.total) FROM credit_notes cn
                              WHERE cn.invoice_id = i.id AND cn.organisation_id = i.organisation_id), 0) AS credited_amount
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id AND c.organisation_id = i.organisation_id
             WHERE i.id = :invoice_id AND i.organisation_id = :organisation_id'
        );
        $invoiceQuery->execute(['invoice_id' => $invoiceId, 'organisation_id' => $organisationId]);
        $invoice = $invoiceQuery->fetch();
        if ($invoice === false) {
            throw new ApiException(404, 'invoice_not_found', 'The invoice was not found in your organisation.');
        }
        $financials = $this->invoiceFinancials($organisationId, $invoiceId, (float) $invoice['total']);
        $itemsQuery = $this->pdo->prepare(
            'SELECT ii.id, ii.product_id, ii.description, ii.quantity, ii.unit_price, ii.unit_cost, ii.total,
                    COALESCE((SELECT SUM(cni.quantity) FROM credit_note_items cni
                              JOIN credit_notes cn ON cn.id = cni.credit_note_id
                              WHERE cni.invoice_item_id = ii.id AND cn.organisation_id = :credits_org), 0) AS credited_quantity
             FROM invoice_items ii
             WHERE ii.invoice_id = :invoice_id ORDER BY ii.id'
        );
        $itemsQuery->execute(['credits_org' => $organisationId, 'invoice_id' => $invoiceId]);
        return [
            'invoice' => array_merge($invoice, [
                'balance_due' => $financials['balance_due'],
                'net_paid' => $financials['net_paid'],
                'net_total' => $financials['net_total'],
            ]),
            'items' => $itemsQuery->fetchAll(),
            'credit_notes' => $this->creditNotes($organisationId, $invoiceId)['credit_notes'],
        ];
    }

    public function customers(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, status, created_at FROM customers
             WHERE organisation_id = :organisation_id ORDER BY name ASC LIMIT 500'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return ['customers' => $statement->fetchAll()];
    }

    public function createCustomer(int $organisationId, int $userId, string $name, ?string $email): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO customers (organisation_id, name, email, status, created_at)
                 VALUES (:organisation_id, :name, :email, 'active', CURRENT_TIMESTAMP)"
            );
            $statement->execute([
                'organisation_id' => $organisationId,
                'name' => $name,
                'email' => $email,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->record($organisationId, $userId, 'customer.created', 'customer', ['customer_id' => $id]);
            $this->pdo->commit();
            return ['id' => $id, 'name' => $name, 'email' => $email, 'status' => 'active'];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createInvoice(int $organisationId, int $userId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->assertOwned('customers', $input['customer_id'], $organisationId);
            $this->assertOptionalOwned('branches', $input['branch_id'] ?? null, $organisationId);

            $items = [];
            $total = 0.0;
            $resolvedBranchId = $input['branch_id'] ?? null;
            $productBranchIds = [];
            $productQuery = $this->pdo->prepare(
                'SELECT id, name, selling_price, unit_cost, stock_level, branch_id FROM products
                 WHERE id = :product_id AND organisation_id = :organisation_id' . $this->lockSuffix()
            );
            $productStates = [];
            foreach ($input['items'] as $item) {
                if ($item['product_id'] === null) {
                    throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                        'items' => 'Invoice lines must reference an organisation product.'
                    ]);
                }
                $productQuery->execute([
                    'product_id' => $item['product_id'],
                    'organisation_id' => $organisationId,
                ]);
                $product = $productQuery->fetch();
                if ($product === false) {
                    throw new ApiException(404, 'product_not_found', 'A selected product was not found in your organisation.');
                }
                $productId = (int) $product['id'];
                $productStates[$productId] ??= [
                    'stock_level' => (int) $product['stock_level'],
                    'unit_cost' => (float) $product['unit_cost'],
                    'branch_id' => $product['branch_id'],
                ];
                $quantity = (int) $item['quantity'];
                if ($productStates[$productId]['stock_level'] < $quantity) {
                    throw new ApiException(409, 'insufficient_stock', 'There is not enough stock to issue this invoice.', [
                        'product_id' => $productId,
                        'available' => $productStates[$productId]['stock_level'],
                        'requested' => $quantity,
                    ]);
                }
                if (($input['branch_id'] ?? null) !== null && $product['branch_id'] !== null
                    && (int) $input['branch_id'] !== (int) $product['branch_id']) {
                    throw new ApiException(422, 'validation_failed', 'A product is not assigned to the invoice branch.', [
                        'branch_id' => 'Use products assigned to the selected branch.'
                    ]);
                }
                if ($product['branch_id'] !== null) {
                    $productBranchIds[(int) $product['branch_id']] = true;
                }
                $productStates[$productId]['stock_level'] -= $quantity;
                $unitPrice = (float) $product['selling_price'];
                $description = (string) $product['name'];
                $lineTotal = round($unitPrice * $quantity, 2);
                $total = round($total + $lineTotal, 2);
                $items[] = [
                    'product_id' => $productId,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $productStates[$productId]['unit_cost'],
                    'branch_id' => $productStates[$productId]['branch_id'],
                    'total' => $lineTotal,
                ];
            }
            if ($resolvedBranchId === null && count($productBranchIds) === 1) {
                $resolvedBranchId = (int) array_key_first($productBranchIds);
            } elseif ($resolvedBranchId === null && count($productBranchIds) > 1) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'branch_id' => 'Select one branch for an invoice that includes products from multiple branches.'
                ]);
            }

            $invoiceNumber = 'INV-' . str_replace('-', '', substr($input['issue_date'], 0, 10)) . '-' . strtoupper(bin2hex(random_bytes(4)));
            $taxAmount = (float) ($input['tax_amount'] ?? '0.00');
            $invoiceTotal = round($total + $taxAmount, 2);
            $invoice = $this->pdo->prepare(
                "INSERT INTO invoices
                    (organisation_id, customer_id, branch_id, invoice_number, issue_date, due_date, total, tax_amount, paid_amount, status, created_at)
                 VALUES
                    (:organisation_id, :customer_id, :branch_id, :invoice_number, :issue_date, :due_date, :total, :tax_amount, 0, 'issued', CURRENT_TIMESTAMP)"
            );
            $invoice->execute([
                'organisation_id' => $organisationId,
                'customer_id' => $input['customer_id'],
                'branch_id' => $resolvedBranchId,
                'invoice_number' => $invoiceNumber,
                'issue_date' => $input['issue_date'],
                'due_date' => $input['due_date'],
                'total' => $invoiceTotal,
                'tax_amount' => $taxAmount,
            ]);
            $invoiceId = (int) $this->pdo->lastInsertId();
            $sale = $this->pdo->prepare(
                "INSERT INTO sales
                    (organisation_id, branch_id, customer_id, sale_date, subtotal, discount, tax, total, status, created_at)
                 VALUES
                    (:organisation_id, :branch_id, :customer_id, :sale_date, :subtotal, 0, :tax, :total, 'posted', CURRENT_TIMESTAMP)"
            );
            $sale->execute([
                'organisation_id' => $organisationId,
                'branch_id' => $resolvedBranchId,
                'customer_id' => $input['customer_id'],
                'sale_date' => $input['issue_date'],
                'subtotal' => $total,
                'tax' => $taxAmount,
                'total' => $invoiceTotal,
            ]);
            $saleId = (int) $this->pdo->lastInsertId();
            $linkInvoice = $this->pdo->prepare(
                'UPDATE invoices SET sale_id = :sale_id WHERE id = :invoice_id AND organisation_id = :organisation_id'
            );
            $linkInvoice->execute([
                'sale_id' => $saleId,
                'invoice_id' => $invoiceId,
                'organisation_id' => $organisationId,
            ]);
            $itemInsert = $this->pdo->prepare(
                'INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, unit_cost, total)
                 VALUES (:invoice_id, :product_id, :description, :quantity, :unit_price, :unit_cost, :total)'
            );
            $saleItemInsert = $this->pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, unit_cost, discount, total)
                 VALUES (:sale_id, :product_id, :quantity, :unit_price, :unit_cost, 0, :total)'
            );
            $decrementStock = $this->pdo->prepare(
                'UPDATE products SET stock_level = stock_level - :quantity
                 WHERE id = :product_id AND organisation_id = :organisation_id AND stock_level >= :minimum'
            );
            $movement = $this->pdo->prepare(
                "INSERT INTO stock_movements
                    (organisation_id, product_id, branch_id, movement_type, quantity, unit_cost, reference_type, reference_id, notes, created_at)
                 VALUES
                    (:organisation_id, :product_id, :branch_id, 'sale', :quantity, :unit_cost, 'invoice', :reference_id, :notes, CURRENT_TIMESTAMP)"
            );
            foreach ($items as $item) {
                $itemInsert->execute([
                    'invoice_id' => $invoiceId,
                    'product_id' => $item['product_id'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['unit_cost'],
                    'total' => $item['total'],
                ]);
                $saleItemInsert->execute([
                    'sale_id' => $saleId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['unit_cost'],
                    'total' => $item['total'],
                ]);
                $decrementStock->execute([
                    'quantity' => $item['quantity'],
                    'product_id' => $item['product_id'],
                    'organisation_id' => $organisationId,
                    'minimum' => $item['quantity'],
                ]);
                if ($decrementStock->rowCount() !== 1) {
                    throw new ApiException(409, 'insufficient_stock', 'Stock changed while the invoice was being issued.');
                }
                $movement->execute([
                    'organisation_id' => $organisationId,
                    'product_id' => $item['product_id'],
                    'branch_id' => $resolvedBranchId ?? $item['branch_id'],
                    'quantity' => -$item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'reference_id' => $invoiceId,
                    'notes' => 'Stock issued with invoice ' . $invoiceNumber . '.',
                ]);
            }
            $this->audit->record($organisationId, $userId, 'invoice.issued', 'invoice', [
                'invoice_id' => $invoiceId,
                'sale_id' => $saleId,
                'invoice_number' => $invoiceNumber,
                'total' => $invoiceTotal,
            ]);
            $this->pdo->commit();
            return ['id' => $invoiceId, 'sale_id' => $saleId, 'invoice_number' => $invoiceNumber, 'total' => $invoiceTotal, 'status' => 'issued'];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function recordPayment(int $organisationId, int $userId, int $invoiceId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $invoiceQuery = $this->pdo->prepare(
                'SELECT id, customer_id, total, due_date FROM invoices
                 WHERE id = :invoice_id AND organisation_id = :organisation_id' . $this->lockSuffix()
            );
            $invoiceQuery->execute(['invoice_id' => $invoiceId, 'organisation_id' => $organisationId]);
            $invoice = $invoiceQuery->fetch();
            if ($invoice === false) {
                throw new ApiException(404, 'invoice_not_found', 'The invoice was not found in your organisation.');
            }
            $financials = $this->invoiceFinancials($organisationId, $invoiceId, (float) $invoice['total']);
            $amount = (float) $input['amount'];
            $balanceDue = $financials['balance_due'];
            if ($amount > $balanceDue) {
                throw new ApiException(409, 'payment_exceeds_balance', 'The payment cannot exceed the invoice balance.', [
                    'balance_due' => number_format($balanceDue, 2, '.', ''),
                ]);
            }
            if ($balanceDue <= 0) {
                throw new ApiException(409, 'invoice_settled', 'The invoice has no remaining balance.');
            }

            $payment = $this->pdo->prepare(
                'INSERT INTO payments
                    (organisation_id, invoice_id, customer_id, payment_date, amount, method, reference, created_at)
                 VALUES
                    (:organisation_id, :invoice_id, :customer_id, :payment_date, :amount, :method, :reference, CURRENT_TIMESTAMP)'
            );
            $payment->execute([
                'organisation_id' => $organisationId,
                'invoice_id' => $invoiceId,
                'customer_id' => $invoice['customer_id'],
                'payment_date' => $input['payment_date'],
                'amount' => $amount,
                'method' => $input['method'],
                'reference' => $input['reference'],
            ]);
            $paymentId = (int) $this->pdo->lastInsertId();
            $paidAmount = round($financials['net_paid'] + $amount, 2);
            $newStatus = $paidAmount >= ($financials['net_total'])
                ? 'paid'
                : (substr((string) $invoice['due_date'], 0, 10) < gmdate('Y-m-d') ? 'overdue' : 'partial');
            $update = $this->pdo->prepare(
                'UPDATE invoices SET paid_amount = :paid_amount, status = :status
                 WHERE id = :invoice_id AND organisation_id = :organisation_id'
            );
            $update->execute([
                'paid_amount' => round($paidAmount, 2),
                'status' => $newStatus,
                'invoice_id' => $invoiceId,
                'organisation_id' => $organisationId,
            ]);
            $this->audit->record($organisationId, $userId, 'payment.recorded', 'payment', [
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
            ]);
            $this->pdo->commit();
            return [
                'id' => $paymentId,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'paid_amount' => round($paidAmount, 2),
                'balance_due' => round(max($financials['net_total'] - $paidAmount, 0), 2),
                'invoice_status' => $newStatus,
            ];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createCreditNote(int $organisationId, int $userId, int $invoiceId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $invoiceQuery = $this->pdo->prepare(
                'SELECT id, sale_id, branch_id, total, tax_amount, due_date
                 FROM invoices
                 WHERE id = :invoice_id AND organisation_id = :organisation_id' . $this->lockSuffix()
            );
            $invoiceQuery->execute(['invoice_id' => $invoiceId, 'organisation_id' => $organisationId]);
            $invoice = $invoiceQuery->fetch();
            if ($invoice === false) {
                throw new ApiException(404, 'invoice_not_found', 'The invoice was not found in your organisation.');
            }
            if ($invoice['sale_id'] === null) {
                throw new ApiException(409, 'invoice_not_creditable', 'Only invoices linked to a posted sale can be credited.');
            }

            $items = [];
            $subtotal = 0.0;
            $remainingSubtotal = round((float) $invoice['total'] - (float) $invoice['tax_amount'], 2);
            $creditedSubtotalQuery = $this->pdo->prepare(
                'SELECT COALESCE(SUM(subtotal), 0) FROM credit_notes
                 WHERE invoice_id = :invoice_id AND organisation_id = :organisation_id'
            );
            $creditedSubtotalQuery->execute(['invoice_id' => $invoiceId, 'organisation_id' => $organisationId]);
            $remainingSubtotal -= (float) $creditedSubtotalQuery->fetchColumn();

            $remainingTaxQuery = $this->pdo->prepare(
                'SELECT COALESCE(SUM(tax_amount), 0) FROM credit_notes
                 WHERE invoice_id = :invoice_id AND organisation_id = :organisation_id'
            );
            $remainingTaxQuery->execute(['invoice_id' => $invoiceId, 'organisation_id' => $organisationId]);
            $remainingTax = round((float) $invoice['tax_amount'] - (float) $remainingTaxQuery->fetchColumn(), 2);

            $lineQuery = $this->pdo->prepare(
                'SELECT ii.id, ii.product_id, ii.quantity, ii.unit_price, ii.unit_cost,
                        COALESCE((SELECT SUM(cni.quantity) FROM credit_note_items cni
                                  JOIN credit_notes cn ON cn.id = cni.credit_note_id
                                  WHERE cni.invoice_item_id = ii.id AND cn.organisation_id = :credits_org), 0) AS credited_quantity
                 FROM invoice_items ii
                 WHERE ii.id = :invoice_item AND ii.invoice_id = :invoice_id' . $this->lockSuffix()
            );
            $uniqueItems = [];
            foreach ($input['items'] as $requestedItem) {
                $itemId = (int) $requestedItem['invoice_item_id'];
                if (isset($uniqueItems[$itemId])) {
                    throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                        'items' => 'Each invoice line may appear only once in a credit note.'
                    ]);
                }
                $uniqueItems[$itemId] = true;
                $lineQuery->execute([
                    'credits_org' => $organisationId,
                    'invoice_item' => $itemId,
                    'invoice_id' => $invoiceId,
                ]);
                $line = $lineQuery->fetch();
                if ($line === false) {
                    throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                        'items' => 'A selected invoice line does not belong to this invoice.'
                    ]);
                }
                $quantity = (int) $requestedItem['quantity'];
                $availableQuantity = (int) $line['quantity'] - (int) $line['credited_quantity'];
                if ($quantity < 1 || $quantity > $availableQuantity) {
                    throw new ApiException(409, 'credit_quantity_exceeded', 'The credit quantity exceeds the invoice line quantity still available.', [
                        'invoice_item_id' => $itemId,
                        'available_quantity' => max($availableQuantity, 0),
                    ]);
                }
                $lineSubtotal = round((float) $line['unit_price'] * $quantity, 2);
                $taxAmount = $remainingSubtotal > 0
                    ? min($remainingTax, round($remainingTax * $lineSubtotal / $remainingSubtotal, 2))
                    : 0.0;
                $restock = (bool) $requestedItem['return_to_stock'];
                if ($restock && $line['product_id'] === null) {
                    throw new ApiException(409, 'product_unavailable', 'The credited product no longer exists, so stock cannot be returned.');
                }
                $subtotal = round($subtotal + $lineSubtotal, 2);
                $remainingSubtotal = round($remainingSubtotal - $lineSubtotal, 2);
                $remainingTax = round($remainingTax - $taxAmount, 2);
                $items[] = [
                    'invoice_item_id' => $itemId,
                    'product_id' => $line['product_id'] === null ? null : (int) $line['product_id'],
                    'quantity' => $quantity,
                    'returned_quantity' => $restock ? $quantity : 0,
                    'unit_price' => (float) $line['unit_price'],
                    'unit_cost' => (float) $line['unit_cost'],
                    'subtotal' => $lineSubtotal,
                    'tax_amount' => $taxAmount,
                    'total' => round($lineSubtotal + $taxAmount, 2),
                ];
            }

            $taxTotal = round(array_sum(array_column($items, 'tax_amount')), 2);
            $creditTotal = round($subtotal + $taxTotal, 2);
            if ($creditTotal <= 0) {
                throw new ApiException(422, 'validation_failed', 'The credit amount must be positive.');
            }
            $current = $this->invoiceFinancials($organisationId, $invoiceId, (float) $invoice['total']);
            if ($creditTotal > $current['net_total']) {
                throw new ApiException(409, 'credit_exceeds_invoice', 'Credits cannot exceed the remaining invoice amount.');
            }
            $paidInvoice = $current['balance_due'] <= 0 && $current['net_paid'] > 0;
            if ($paidInvoice && $input['refund_method'] === null) {
                throw new ApiException(422, 'refund_method_required', 'A paid invoice credit must be refunded using a payment method.');
            }
            if (!$paidInvoice && $input['refund_method'] !== null) {
                throw new ApiException(409, 'invoice_not_settled', 'Cash refunds are only supported after the invoice is fully paid.');
            }
            if ($paidInvoice && $creditTotal > $current['net_paid']) {
                throw new ApiException(409, 'refund_exceeds_paid_amount', 'The credit exceeds the amount collected and still held.');
            }

            $creditNumber = 'CN-' . str_replace('-', '', substr(date('Y-m-d'), 0, 10)) . '-' . strtoupper(bin2hex(random_bytes(4)));
            $creditInsert = $this->pdo->prepare(
                'INSERT INTO credit_notes
                    (organisation_id, invoice_id, credit_number, reason, subtotal, tax_amount, total, stock_returned, created_by_user_id, created_at)
                 VALUES
                    (:organisation_id, :invoice_id, :credit_number, :reason, :subtotal, :tax_amount, :total, :stock_returned, :user_id, CURRENT_TIMESTAMP)'
            );
            $creditInsert->execute([
                'organisation_id' => $organisationId,
                'invoice_id' => $invoiceId,
                'credit_number' => $creditNumber,
                'reason' => $input['reason'],
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total' => $creditTotal,
                'stock_returned' => count(array_filter($items, static fn (array $item): bool => $item['returned_quantity'] > 0)) > 0 ? 1 : 0,
                'user_id' => $userId,
            ]);
            $creditId = (int) $this->pdo->lastInsertId();
            $creditItemInsert = $this->pdo->prepare(
                'INSERT INTO credit_note_items
                    (credit_note_id, invoice_item_id, product_id, quantity, returned_quantity, unit_price, unit_cost, subtotal, tax_amount, total)
                 VALUES
                    (:credit_note_id, :invoice_item_id, :product_id, :quantity, :returned_quantity, :unit_price, :unit_cost, :subtotal, :tax_amount, :total)'
            );
            $movement = $this->pdo->prepare(
                "INSERT INTO stock_movements
                    (organisation_id, product_id, branch_id, movement_type, quantity, unit_cost, reference_type, reference_id, notes, created_at)
                 VALUES
                    (:organisation_id, :product_id, :branch_id, 'return', :quantity, :unit_cost, 'invoice_credit', :reference_id, :notes, CURRENT_TIMESTAMP)"
            );
            foreach ($items as $item) {
                $creditItemInsert->execute([
                    'credit_note_id' => $creditId,
                    'invoice_item_id' => $item['invoice_item_id'],
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'returned_quantity' => $item['returned_quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['unit_cost'],
                    'subtotal' => $item['subtotal'],
                    'tax_amount' => $item['tax_amount'],
                    'total' => $item['total'],
                ]);
                if ($item['returned_quantity'] === 0) {
                    continue;
                }
                $productQuery = $this->pdo->prepare(
                    'SELECT stock_level, unit_cost, branch_id FROM products
                     WHERE id = :product_id AND organisation_id = :organisation_id' . $this->lockSuffix()
                );
                $productQuery->execute(['product_id' => $item['product_id'], 'organisation_id' => $organisationId]);
                $product = $productQuery->fetch();
                if ($product === false) {
                    throw new ApiException(409, 'product_unavailable', 'The credited product is no longer available in this organisation.');
                }
                $newQuantity = (int) $product['stock_level'] + $item['returned_quantity'];
                $newCost = $newQuantity > 0
                    ? round((((int) $product['stock_level'] * (float) $product['unit_cost'])
                        + ($item['returned_quantity'] * $item['unit_cost'])) / $newQuantity, 2)
                    : $item['unit_cost'];
                $updateProduct = $this->pdo->prepare(
                    'UPDATE products SET stock_level = :stock_level, unit_cost = :unit_cost
                     WHERE id = :product_id AND organisation_id = :organisation_id'
                );
                $updateProduct->execute([
                    'stock_level' => $newQuantity,
                    'unit_cost' => $newCost,
                    'product_id' => $item['product_id'],
                    'organisation_id' => $organisationId,
                ]);
                $movement->execute([
                    'organisation_id' => $organisationId,
                    'product_id' => $item['product_id'],
                    'branch_id' => $invoice['branch_id'],
                    'quantity' => $item['returned_quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'reference_id' => $creditId,
                    'notes' => 'Stock returned under credit note ' . $creditNumber . '.',
                ]);
            }

            if ($paidInvoice) {
                $refundInsert = $this->pdo->prepare(
                    'INSERT INTO invoice_refunds
                        (organisation_id, invoice_id, credit_note_id, amount, method, reference, refunded_at, created_by_user_id)
                     VALUES
                        (:organisation_id, :invoice_id, :credit_note_id, :amount, :method, :reference, CURRENT_TIMESTAMP, :user_id)'
                );
                $refundInsert->execute([
                    'organisation_id' => $organisationId,
                    'invoice_id' => $invoiceId,
                    'credit_note_id' => $creditId,
                    'amount' => $creditTotal,
                    'method' => $input['refund_method'],
                    'reference' => $input['refund_reference'],
                    'user_id' => $userId,
                ]);
            }

            $updatedTotals = $this->invoiceFinancials($organisationId, $invoiceId, (float) $invoice['total']);
            $status = $updatedTotals['balance_due'] <= 0
                ? 'paid'
                : (substr((string) $invoice['due_date'], 0, 10) < gmdate('Y-m-d') ? 'overdue' : 'partial');
            $invoiceUpdate = $this->pdo->prepare(
                'UPDATE invoices SET paid_amount = :paid_amount, status = :status
                 WHERE id = :invoice_id AND organisation_id = :organisation_id'
            );
            $invoiceUpdate->execute([
                'paid_amount' => $updatedTotals['net_paid'],
                'status' => $status,
                'invoice_id' => $invoiceId,
                'organisation_id' => $organisationId,
            ]);
            $this->audit->record($organisationId, $userId, 'invoice.credit_issued', 'credit_note', [
                'credit_note_id' => $creditId,
                'invoice_id' => $invoiceId,
                'credit_number' => $creditNumber,
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total' => $creditTotal,
                'refunded' => $paidInvoice,
                'stock_returned' => count(array_filter($items, static fn (array $item): bool => $item['returned_quantity'] > 0)),
                'reason' => $input['reason'],
            ]);
            $this->pdo->commit();
            return [
                'id' => $creditId,
                'credit_number' => $creditNumber,
                'invoice_id' => $invoiceId,
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total' => $creditTotal,
                'refunded' => $paidInvoice,
                'balance_due' => $updatedTotals['balance_due'],
                'invoice_status' => $status,
            ];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function creditNotes(int $organisationId, int $invoiceId): array
    {
        $this->assertInvoiceOwned($organisationId, $invoiceId);
        $statement = $this->pdo->prepare(
            'SELECT id, credit_number, reason, subtotal, tax_amount, total, stock_returned, created_at
             FROM credit_notes
             WHERE organisation_id = :organisation_id AND invoice_id = :invoice_id
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['organisation_id' => $organisationId, 'invoice_id' => $invoiceId]);
        return ['credit_notes' => $statement->fetchAll()];
    }

    private function invoiceFinancials(int $organisationId, int $invoiceId, float $invoiceTotal): array
    {
        $queries = [
            'credits' => 'SELECT COALESCE(SUM(total), 0) FROM credit_notes WHERE organisation_id = :organisation_id AND invoice_id = :invoice_id',
            'payments' => 'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE organisation_id = :organisation_id AND invoice_id = :invoice_id',
            'refunds' => 'SELECT COALESCE(SUM(amount), 0) FROM invoice_refunds WHERE organisation_id = :organisation_id AND invoice_id = :invoice_id',
        ];
        $values = [];
        foreach ($queries as $key => $sql) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['organisation_id' => $organisationId, 'invoice_id' => $invoiceId]);
            $values[$key] = (float) $statement->fetchColumn();
        }
        $netTotal = round($invoiceTotal - $values['credits'], 2);
        $netPaid = round($values['payments'] - $values['refunds'], 2);
        return [
            'credits' => $values['credits'],
            'payments' => $values['payments'],
            'refunds' => $values['refunds'],
            'net_total' => $netTotal,
            'net_paid' => $netPaid,
            'balance_due' => round(max($netTotal - $netPaid, 0), 2),
        ];
    }

    private function assertInvoiceOwned(int $organisationId, int $invoiceId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM invoices WHERE id = :invoice_id AND organisation_id = :organisation_id LIMIT 1'
        );
        $statement->execute(['invoice_id' => $invoiceId, 'organisation_id' => $organisationId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(404, 'invoice_not_found', 'The invoice was not found in your organisation.');
        }
    }

    public function suppliers(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, contact_email, status, created_at FROM suppliers
             WHERE organisation_id = :organisation_id ORDER BY name ASC'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return ['suppliers' => $statement->fetchAll()];
    }

    public function createSupplier(int $organisationId, int $userId, string $name, ?string $email): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO suppliers (organisation_id, name, contact_email, status, created_at)
                 VALUES (:organisation_id, :name, :contact_email, 'active', CURRENT_TIMESTAMP)"
            );
            $statement->execute([
                'organisation_id' => $organisationId,
                'name' => $name,
                'contact_email' => $email,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->record($organisationId, $userId, 'supplier.created', 'supplier', ['supplier_id' => $id]);
            $this->pdo->commit();
            return ['id' => $id, 'name' => $name, 'contact_email' => $email, 'status' => 'active'];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function purchaseOrders(int $organisationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT po.id, po.order_date, po.expected_date, po.status, po.total, s.name AS supplier_name,
                    b.name AS branch_name,
                    (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id AND s.organisation_id = po.organisation_id
             LEFT JOIN branches b ON b.id = po.branch_id AND b.organisation_id = po.organisation_id
             WHERE po.organisation_id = :organisation_id
             ORDER BY po.order_date DESC, po.id DESC LIMIT 100'
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return ['purchase_orders' => $statement->fetchAll()];
    }

    public function createPurchaseOrder(int $organisationId, int $userId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->assertOwned('suppliers', $input['supplier_id'], $organisationId);
            $this->assertOptionalOwned('branches', $input['branch_id'] ?? null, $organisationId);
            $productQuery = $this->pdo->prepare(
                'SELECT id, branch_id FROM products WHERE id = :product_id AND organisation_id = :organisation_id'
            );
            $items = [];
            $total = 0.0;
            foreach ($input['items'] as $item) {
                $productQuery->execute([
                    'product_id' => $item['product_id'],
                    'organisation_id' => $organisationId,
                ]);
                $product = $productQuery->fetch();
                if ($product === false) {
                    throw new ApiException(404, 'product_not_found', 'A selected product was not found in your organisation.');
                }
                if (($input['branch_id'] ?? null) !== null && $product['branch_id'] !== null
                    && (int) $product['branch_id'] !== (int) $input['branch_id']) {
                    throw new ApiException(422, 'validation_failed', 'A product is not assigned to the purchase-order branch.', [
                        'items' => 'Use products assigned to this branch.'
                    ]);
                }
                $lineTotal = round((float) $item['unit_cost'] * (int) $item['quantity'], 2);
                $total = round($total + $lineTotal, 2);
                $items[] = $item + ['total' => $lineTotal];
            }
            $order = $this->pdo->prepare(
                "INSERT INTO purchase_orders
                    (organisation_id, supplier_id, branch_id, order_date, expected_date, status, total, created_at)
                 VALUES
                    (:organisation_id, :supplier_id, :branch_id, :order_date, :expected_date, 'draft', :total, CURRENT_TIMESTAMP)"
            );
            $order->execute([
                'organisation_id' => $organisationId,
                'supplier_id' => $input['supplier_id'],
                'branch_id' => $input['branch_id'] ?? null,
                'order_date' => $input['order_date'],
                'expected_date' => $input['expected_date'],
                'total' => $total,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();
            $itemInsert = $this->pdo->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost, total)
                 VALUES (:purchase_order_id, :product_id, :quantity, :unit_cost, :total)'
            );
            foreach ($items as $item) {
                $itemInsert->execute([
                    'purchase_order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total' => $item['total'],
                ]);
            }
            $this->audit->record($organisationId, $userId, 'purchase_order.created', 'purchase_order', [
                'purchase_order_id' => $orderId,
                'total' => $total,
            ]);
            $this->pdo->commit();
            return ['id' => $orderId, 'total' => $total, 'status' => 'draft'];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function submitPurchaseOrder(int $organisationId, int $userId, int $orderId): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "UPDATE purchase_orders SET status = 'submitted'
                 WHERE id = :order_id AND organisation_id = :organisation_id AND status = 'draft'"
            );
            $statement->execute(['order_id' => $orderId, 'organisation_id' => $organisationId]);
            if ($statement->rowCount() !== 1) {
                $this->assertPurchaseOrderExists($organisationId, $orderId);
                throw new ApiException(409, 'purchase_order_state_conflict', 'Only a draft purchase order can be submitted.');
            }
            $this->audit->record($organisationId, $userId, 'purchase_order.submitted', 'purchase_order', ['purchase_order_id' => $orderId]);
            $this->pdo->commit();
            return ['id' => $orderId, 'status' => 'submitted'];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function receivePurchaseOrder(int $organisationId, int $userId, int $orderId): array
    {
        $this->pdo->beginTransaction();
        try {
            $orderQuery = $this->pdo->prepare(
                'SELECT id, branch_id, status FROM purchase_orders
                 WHERE id = :order_id AND organisation_id = :organisation_id' . $this->lockSuffix()
            );
            $orderQuery->execute(['order_id' => $orderId, 'organisation_id' => $organisationId]);
            $order = $orderQuery->fetch();
            if ($order === false) {
                throw new ApiException(404, 'purchase_order_not_found', 'The purchase order was not found in your organisation.');
            }
            if ($order['status'] !== 'submitted') {
                throw new ApiException(409, 'purchase_order_state_conflict', 'Only a submitted purchase order can be received.');
            }
            $lockProducts = $this->pdo->prepare(
                'SELECT p.id
                 FROM products p
                 JOIN purchase_order_items poi ON poi.product_id = p.id
                 WHERE poi.purchase_order_id = :order_id AND p.organisation_id = :organisation_id
                 ORDER BY p.id' . $this->lockSuffix()
            );
            $lockProducts->execute(['order_id' => $orderId, 'organisation_id' => $organisationId]);
            $lockProducts->fetchAll();
            $itemsQuery = $this->pdo->prepare(
                'SELECT poi.product_id, SUM(poi.quantity) AS quantity, SUM(poi.total) AS total_cost,
                        p.stock_level, p.unit_cost AS current_unit_cost, p.branch_id
                 FROM purchase_order_items poi
                 JOIN products p ON p.id = poi.product_id AND p.organisation_id = :organisation_id
                 WHERE poi.purchase_order_id = :order_id'
                 . ' GROUP BY poi.product_id, p.stock_level, p.unit_cost, p.branch_id'
            );
            $itemsQuery->execute(['organisation_id' => $organisationId, 'order_id' => $orderId]);
            $items = $itemsQuery->fetchAll();
            if ($items === []) {
                throw new ApiException(409, 'empty_purchase_order', 'The purchase order has no items to receive.');
            }

            $updateProduct = $this->pdo->prepare(
                'UPDATE products SET stock_level = :stock_level, unit_cost = :unit_cost
                 WHERE id = :product_id AND organisation_id = :organisation_id'
            );
            $movementItemsQuery = $this->pdo->prepare(
                'SELECT product_id, quantity, unit_cost FROM purchase_order_items
                 WHERE purchase_order_id = :order_id ORDER BY id ASC'
            );
            $movement = $this->pdo->prepare(
                "INSERT INTO stock_movements
                    (organisation_id, product_id, branch_id, movement_type, quantity, unit_cost, reference_type, reference_id, notes, created_at)
                 VALUES
                    (:organisation_id, :product_id, :branch_id, 'receipt', :quantity, :unit_cost, 'purchase_order', :reference_id, :notes, CURRENT_TIMESTAMP)"
            );
            $productBranches = [];
            foreach ($items as $item) {
                if ($order['branch_id'] !== null && $item['branch_id'] !== null
                    && (int) $order['branch_id'] !== (int) $item['branch_id']) {
                    throw new ApiException(409, 'purchase_order_branch_mismatch', 'A product no longer matches the purchase-order branch.');
                }
                $oldQuantity = (int) $item['stock_level'];
                $receivedQuantity = (int) $item['quantity'];
                $newQuantity = $oldQuantity + $receivedQuantity;
                $receiptUnitCost = $receivedQuantity > 0 ? (float) $item['total_cost'] / $receivedQuantity : 0.0;
                $newCost = $newQuantity > 0
                    ? round((($oldQuantity * (float) $item['current_unit_cost']) + (float) $item['total_cost']) / $newQuantity, 2)
                    : $receiptUnitCost;
                $updateProduct->execute([
                    'stock_level' => $newQuantity,
                    'unit_cost' => $newCost,
                    'product_id' => $item['product_id'],
                    'organisation_id' => $organisationId,
                ]);
                $productBranches[(int) $item['product_id']] = $order['branch_id'] ?? $item['branch_id'];
            }
            $movementItemsQuery->execute(['order_id' => $orderId]);
            foreach ($movementItemsQuery->fetchAll() as $item) {
                $movement->execute([
                    'organisation_id' => $organisationId,
                    'product_id' => $item['product_id'],
                    'branch_id' => $productBranches[(int) $item['product_id']] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'reference_id' => $orderId,
                    'notes' => 'Purchase order received.',
                ]);
            }
            $orderUpdate = $this->pdo->prepare(
                "UPDATE purchase_orders SET status = 'received'
                 WHERE id = :order_id AND organisation_id = :organisation_id AND status = 'submitted'"
            );
            $orderUpdate->execute(['order_id' => $orderId, 'organisation_id' => $organisationId]);
            if ($orderUpdate->rowCount() !== 1) {
                throw new ApiException(409, 'purchase_order_state_conflict', 'Purchase order status changed during receipt.');
            }
            $this->audit->record($organisationId, $userId, 'purchase_order.received', 'purchase_order', [
                'purchase_order_id' => $orderId,
                'item_count' => count($items),
            ]);
            $this->pdo->commit();
            return ['id' => $orderId, 'status' => 'received', 'item_count' => count($items)];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function assertPurchaseOrderExists(int $organisationId, int $orderId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM purchase_orders WHERE id = :order_id AND organisation_id = :organisation_id LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId, 'organisation_id' => $organisationId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(404, 'purchase_order_not_found', 'The purchase order was not found in your organisation.');
        }
    }

    private function assertOptionalOwned(string $table, mixed $id, int $organisationId): void
    {
        if ($id !== null) {
            $this->assertOwned($table, (int) $id, $organisationId);
        }
    }

    private function assertOwned(string $table, int $id, int $organisationId): void
    {
        if (!in_array($table, ['customers', 'branches', 'suppliers'], true)) {
            throw new \LogicException('Unsupported tenant-owned entity table.');
        }
        $statement = $this->pdo->prepare(
            "SELECT " . (in_array($table, ['customers', 'suppliers'], true) ? 'status' : '1') . "
             FROM {$table} WHERE id = :id AND organisation_id = :organisation_id LIMIT 1"
        );
        $statement->execute(['id' => $id, 'organisation_id' => $organisationId]);
        $status = $statement->fetchColumn();
        if ($status === false) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $table === 'customers' ? 'customer_id' : ($table === 'branches' ? 'branch_id' : 'supplier_id')
                    => 'The selected record does not belong to your organisation.'
            ]);
        }
        if (in_array($table, ['customers', 'suppliers'], true) && $status !== 'active') {
            throw new ApiException(409, 'record_inactive', 'The selected customer or supplier is not active.');
        }
    }

    private function lockSuffix(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
