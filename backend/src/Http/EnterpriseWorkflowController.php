<?php
declare(strict_types=1);

namespace Vantiq\Http;

use DateTimeImmutable;
use Vantiq\Security\AuthMiddleware;
use Vantiq\Security\PermissionMiddleware;
use Vantiq\Service\EnterpriseWorkflowService;

final class EnterpriseWorkflowController extends Controller
{
    public function __construct(
        private readonly EnterpriseWorkflowService $service,
        AuthMiddleware $middleware,
        PermissionMiddleware $permissions
    ) {
        parent::__construct($middleware, $permissions);
    }

    public function invoices(): array
    {
        $user = $this->tenantUser('finance.read');
        return $this->service->invoices((int) $user['organisation_id']);
    }

    public function invoiceDetail(array $params): array
    {
        $user = $this->tenantUser('finance.read');
        return $this->service->invoiceDetail((int) $user['organisation_id'], (int) $params['id']);
    }

    public function customers(): array
    {
        $user = $this->tenantUser('finance.read');
        return $this->service->customers((int) $user['organisation_id']);
    }

    public function createCustomer(Request $request): array
    {
        $user = $this->tenantUser('finance.write');
        $input = $request->json();
        Validator::only($input, ['name', 'email']);
        $email = null;
        if (array_key_exists('email', $input) && $input['email'] !== null && $input['email'] !== '') {
            $email = Validator::email($input, 'email');
        }
        return $this->service->createCustomer(
            (int) $user['organisation_id'],
            (int) $user['id'],
            Validator::requiredString($input, 'name', 180),
            $email
        );
    }

    public function createInvoice(Request $request): array
    {
        $user = $this->tenantUser('finance.write');
        $input = $request->json();
        Validator::only($input, ['customer_id', 'branch_id', 'issue_date', 'due_date', 'tax_amount', 'items']);
        $issueDate = $this->date($input['issue_date'] ?? date('Y-m-d'), 'issue_date');
        $dueDate = $this->date($input['due_date'] ?? null, 'due_date');
        if ($dueDate < $issueDate) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'due_date' => 'The due date must be on or after the issue date.'
            ]);
        }
        $itemsInput = $input['items'] ?? null;
        if (!is_array($itemsInput) || $itemsInput === [] || count($itemsInput) > 100) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'items' => 'Add between 1 and 100 invoice items.'
            ]);
        }
        $items = [];
        foreach ($itemsInput as $item) {
            if ($item instanceof \stdClass) {
                $item = get_object_vars($item);
            }
            if (!is_array($item)) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'items' => 'Each invoice item must be an object.'
                ]);
            }
            Validator::only($item, ['product_id', 'description', 'quantity', 'unit_price']);
            $productId = $this->optionalInteger($item, 'product_id');
            $items[] = [
                'product_id' => $productId,
                'description' => $productId === null
                    ? Validator::requiredString($item, 'description', 255)
                    : (isset($item['description']) && is_string($item['description']) ? trim($item['description']) : ''),
                'quantity' => $this->integer($item, 'quantity', 1, 1000000),
                'unit_price' => $productId !== null && !array_key_exists('unit_price', $item)
                    ? '0.00'
                    : $this->money($item, 'unit_price'),
            ];
        }

        return $this->service->createInvoice((int) $user['organisation_id'], (int) $user['id'], [
            'customer_id' => $this->integer($input, 'customer_id', 1, PHP_INT_MAX),
            'branch_id' => $this->optionalInteger($input, 'branch_id'),
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'tax_amount' => array_key_exists('tax_amount', $input) ? $this->money($input, 'tax_amount') : '0.00',
            'items' => $items,
        ]);
    }

    public function recordPayment(Request $request, array $params): array
    {
        $user = $this->tenantUser('finance.write');
        $input = $request->json();
        Validator::only($input, ['payment_date', 'amount', 'method', 'reference']);
        $method = Validator::requiredString($input, 'method', 60);
        if (!in_array($method, ['cash', 'card', 'bank_transfer', 'cheque', 'other'], true)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'method' => 'Choose cash, card, bank_transfer, cheque, or other.'
            ]);
        }
        return $this->service->recordPayment(
            (int) $user['organisation_id'],
            (int) $user['id'],
            (int) $params['id'],
            [
                'payment_date' => $this->date($input['payment_date'] ?? date('Y-m-d'), 'payment_date'),
                'amount' => $this->money($input, 'amount', true),
                'method' => $method,
                'reference' => array_key_exists('reference', $input) && $input['reference'] !== null
                    ? Validator::requiredString($input, 'reference', 120)
                    : null,
            ]
        );
    }

    public function creditNotes(array $params): array
    {
        $user = $this->tenantUser('finance.read');
        return $this->service->creditNotes((int) $user['organisation_id'], (int) $params['id']);
    }

    public function createCreditNote(Request $request, array $params): array
    {
        $user = $this->tenantUser('finance.write');
        $input = $request->json();
        Validator::only($input, ['reason', 'items', 'refund_method', 'refund_reference']);
        $itemsInput = $input['items'] ?? null;
        if (!is_array($itemsInput) || $itemsInput === [] || count($itemsInput) > 100) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'items' => 'Add between 1 and 100 invoice lines.'
            ]);
        }
        $items = [];
        foreach ($itemsInput as $item) {
            if ($item instanceof \stdClass) {
                $item = get_object_vars($item);
            }
            if (!is_array($item)) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'items' => 'Each credit line must be an object.'
                ]);
            }
            Validator::only($item, ['invoice_item_id', 'quantity', 'return_to_stock']);
            $returnToStock = $item['return_to_stock'] ?? false;
            if (!is_bool($returnToStock)) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'items' => 'return_to_stock must be true or false.'
                ]);
            }
            $items[] = [
                'invoice_item_id' => $this->integer($item, 'invoice_item_id', 1, PHP_INT_MAX),
                'quantity' => $this->integer($item, 'quantity', 1, 1000000),
                'return_to_stock' => $returnToStock,
            ];
        }
        $refundMethod = $input['refund_method'] ?? null;
        if ($refundMethod !== null) {
            if (!is_string($refundMethod) || !in_array($refundMethod, ['cash', 'card', 'bank_transfer', 'cheque', 'other'], true)) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'refund_method' => 'Choose cash, card, bank_transfer, cheque, or other.'
                ]);
            }
        }
        $refundReference = $input['refund_reference'] ?? null;
        if ($refundReference !== null && (!is_string($refundReference) || mb_strlen(trim($refundReference)) > 120)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'refund_reference' => 'Use no more than 120 characters.'
            ]);
        }
        return $this->service->createCreditNote(
            (int) $user['organisation_id'],
            (int) $user['id'],
            (int) $params['id'],
            [
                'reason' => Validator::requiredString($input, 'reason', 500),
                'items' => $items,
                'refund_method' => $refundMethod,
                'refund_reference' => $refundReference === null ? null : trim($refundReference),
            ]
        );
    }

    public function suppliers(): array
    {
        $user = $this->tenantUser('operations.read');
        return $this->service->suppliers((int) $user['organisation_id']);
    }

    public function createSupplier(Request $request): array
    {
        $user = $this->tenantUser('operations.write');
        $input = $request->json();
        Validator::only($input, ['name', 'contact_email']);
        $email = null;
        if (array_key_exists('contact_email', $input) && $input['contact_email'] !== null && $input['contact_email'] !== '') {
            $email = Validator::email($input, 'contact_email');
        }
        return $this->service->createSupplier(
            (int) $user['organisation_id'],
            (int) $user['id'],
            Validator::requiredString($input, 'name', 180),
            $email
        );
    }

    public function purchaseOrders(): array
    {
        $user = $this->tenantUser('operations.read');
        return $this->service->purchaseOrders((int) $user['organisation_id']);
    }

    public function createPurchaseOrder(Request $request): array
    {
        $user = $this->tenantUser('operations.write');
        $input = $request->json();
        Validator::only($input, ['supplier_id', 'branch_id', 'order_date', 'expected_date', 'items']);
        $orderDate = $this->date($input['order_date'] ?? date('Y-m-d'), 'order_date');
        $expectedDate = null;
        if (array_key_exists('expected_date', $input) && $input['expected_date'] !== null && $input['expected_date'] !== '') {
            $expectedDate = $this->date($input['expected_date'], 'expected_date');
            if ($expectedDate < $orderDate) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'expected_date' => 'Expected date must be on or after the order date.'
                ]);
            }
        }
        $itemsInput = $input['items'] ?? null;
        if (!is_array($itemsInput) || $itemsInput === [] || count($itemsInput) > 100) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'items' => 'Add between 1 and 100 purchase-order items.'
            ]);
        }
        $items = [];
        foreach ($itemsInput as $item) {
            if ($item instanceof \stdClass) {
                $item = get_object_vars($item);
            }
            if (!is_array($item)) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'items' => 'Each purchase-order item must be an object.'
                ]);
            }
            Validator::only($item, ['product_id', 'quantity', 'unit_cost']);
            $items[] = [
                'product_id' => $this->integer($item, 'product_id', 1, PHP_INT_MAX),
                'quantity' => $this->integer($item, 'quantity', 1, 1000000),
                'unit_cost' => $this->money($item, 'unit_cost'),
            ];
        }
        return $this->service->createPurchaseOrder((int) $user['organisation_id'], (int) $user['id'], [
            'supplier_id' => $this->integer($input, 'supplier_id', 1, PHP_INT_MAX),
            'branch_id' => $this->optionalInteger($input, 'branch_id'),
            'order_date' => $orderDate,
            'expected_date' => $expectedDate,
            'items' => $items,
        ]);
    }

    public function submitPurchaseOrder(array $params): array
    {
        $user = $this->tenantUser('operations.write');
        return $this->service->submitPurchaseOrder(
            (int) $user['organisation_id'],
            (int) $user['id'],
            (int) $params['id']
        );
    }

    public function receivePurchaseOrder(array $params): array
    {
        $user = $this->tenantUser('operations.write');
        return $this->service->receivePurchaseOrder(
            (int) $user['organisation_id'],
            (int) $user['id'],
            (int) $params['id']
        );
    }

    private function money(array $input, string $field, bool $mustBePositive = false): string
    {
        $value = $input[$field] ?? null;
        if (is_float($value) && is_finite($value)) {
            $value = number_format($value, 2, '.', '');
        } elseif (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/', $value)
            || ($mustBePositive && (float) $value <= 0)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => $mustBePositive ? 'Enter a positive amount with up to two decimal places.' : 'Enter a non-negative amount with up to two decimal places.'
            ]);
        }
        return number_format((float) $value, 2, '.', '');
    }

    private function integer(array $input, string $field, int $minimum, int $maximum): int
    {
        $value = $input[$field] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'Enter a valid integer within the supported range.'
            ]);
        }
        return $value;
    }

    private function optionalInteger(array $input, string $field): ?int
    {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return null;
        }
        return $this->integer($input, $field, 1, PHP_INT_MAX);
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'Use a valid YYYY-MM-DD date.'
            ]);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'Use a valid YYYY-MM-DD date.'
            ]);
        }
        return $date->format('Y-m-d 00:00:00');
    }
}
