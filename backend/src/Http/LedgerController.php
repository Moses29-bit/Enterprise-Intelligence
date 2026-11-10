<?php
declare(strict_types=1);

namespace Vantiq\Http;

use DateTimeImmutable;
use Vantiq\Repository\RoleRepository;
use Vantiq\Security\AuthMiddleware;
use Vantiq\Service\LedgerService;

final class LedgerController
{
    public function __construct(
        private readonly LedgerService $service,
        private readonly AuthMiddleware $middleware,
        private readonly RoleRepository $roles
    ) {
    }

    public function products(): array
    {
        $user = $this->middleware->requireUser();
        if ($user['organisation_id'] === null
            || (!$this->roles->hasPermission((int) $user['id'], 'operations.read')
                && !$this->roles->hasPermission((int) $user['id'], 'finance.read')
                && !$this->roles->hasPermission((int) $user['id'], 'platform.users.manage'))) {
            throw new ApiException(403, 'forbidden', 'You do not have permission to access product records.');
        }
        return $this->service->products((int) $user['organisation_id']);
    }

    public function createProduct(Request $request): array
    {
        $user = $this->tenantUser('operations.write');
        $input = $request->json();
        Validator::only($input, ['name', 'sku', 'unit_cost', 'selling_price', 'reorder_level', 'branch_id', 'category_id']);
        return $this->service->createProduct((int) $user['organisation_id'], (int) $user['id'], [
            'name' => Validator::requiredString($input, 'name', 180),
            'sku' => Validator::requiredString($input, 'sku', 80),
            'unit_cost' => $this->money($input, 'unit_cost'),
            'selling_price' => $this->money($input, 'selling_price'),
            'reorder_level' => $this->integer($input, 'reorder_level', 0, 100000000),
            'branch_id' => $this->optionalInteger($input, 'branch_id'),
            'category_id' => $this->optionalInteger($input, 'category_id'),
        ]);
    }

    public function sales(Request $request): array
    {
        $user = $this->tenantUser('finance.read');
        [$limit, $offset] = $this->pagination($request);
        return $this->service->sales((int) $user['organisation_id'], $limit, $offset);
    }

    public function createSale(Request $request): array
    {
        $user = $this->tenantUser('finance.write');
        $input = $request->json();
        Validator::only($input, ['sale_date', 'branch_id', 'department_id', 'customer_id', 'discount', 'tax', 'items']);
        $itemsInput = $input['items'] ?? null;
        if (!is_array($itemsInput) || $itemsInput === [] || count($itemsInput) > 100) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'items' => 'Add between 1 and 100 products.'
            ]);
        }

        $items = [];
        foreach ($itemsInput as $item) {
            if ($item instanceof \stdClass) {
                $item = get_object_vars($item);
            }
            if (!is_array($item) || array_diff(array_keys($item), ['product_id', 'quantity']) !== []) {
                throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                    'items' => 'Each item must contain only product_id and quantity.'
                ]);
            }
            $items[] = [
                'product_id' => $this->integer($item, 'product_id', 1, PHP_INT_MAX),
                'quantity' => $this->integer($item, 'quantity', 1, 1000000),
            ];
        }
        return $this->service->createSale((int) $user['organisation_id'], (int) $user['id'], [
            'sale_date' => $this->date($input['sale_date'] ?? date('Y-m-d'), 'sale_date'),
            'branch_id' => $this->optionalInteger($input, 'branch_id'),
            'department_id' => $this->optionalInteger($input, 'department_id'),
            'customer_id' => $this->optionalInteger($input, 'customer_id'),
            'discount' => array_key_exists('discount', $input) ? $this->money($input, 'discount') : 0,
            'tax' => array_key_exists('tax', $input) ? $this->money($input, 'tax') : 0,
            'items' => $items,
        ]);
    }

    public function refundSale(Request $request, array $params): array
    {
        $user = $this->tenantUser('finance.write');
        $input = $request->json();
        Validator::only($input, ['reason']);
        return $this->service->refundSale(
            (int) $user['organisation_id'],
            (int) $user['id'],
            (int) $params['id'],
            Validator::requiredString($input, 'reason', 500)
        );
    }

    public function expenses(Request $request): array
    {
        $user = $this->tenantUser('finance.read');
        [$limit, $offset] = $this->pagination($request);
        return $this->service->expenses((int) $user['organisation_id'], $limit, $offset);
    }

    public function createExpense(Request $request): array
    {
        $user = $this->tenantUser('finance.write');
        $input = $request->json();
        Validator::only($input, ['expense_date', 'amount', 'description', 'branch_id', 'department_id', 'category_id']);
        return $this->service->createExpense((int) $user['organisation_id'], (int) $user['id'], [
            'expense_date' => $this->date($input['expense_date'] ?? date('Y-m-d'), 'expense_date'),
            'amount' => $this->money($input, 'amount', true),
            'description' => Validator::requiredString($input, 'description', 255),
            'branch_id' => $this->optionalInteger($input, 'branch_id'),
            'department_id' => $this->optionalInteger($input, 'department_id'),
            'category_id' => $this->optionalInteger($input, 'category_id'),
        ]);
    }

    public function moveStock(Request $request, array $params): array
    {
        $user = $this->tenantUser('operations.write');
        $input = $request->json();
        Validator::only($input, ['movement_type', 'quantity', 'branch_id', 'unit_cost', 'notes']);
        $movementType = Validator::requiredString($input, 'movement_type', 20);
        if (!in_array($movementType, ['receipt', 'adjustment', 'return'], true)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'movement_type' => 'Choose receipt, adjustment, or return.'
            ]);
        }
        $quantity = $this->integer($input, 'quantity', $movementType === 'adjustment' ? -1000000 : 1, 1000000);
        if ($movementType === 'adjustment' && $quantity === 0) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'quantity' => 'An adjustment cannot be zero.'
            ]);
        }
        $payload = [
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'branch_id' => $this->optionalInteger($input, 'branch_id'),
            'notes' => array_key_exists('notes', $input) ? Validator::requiredString($input, 'notes', 255) : null,
        ];
        if (array_key_exists('unit_cost', $input)) {
            $payload['unit_cost'] = $this->money($input, 'unit_cost');
        }
        return $this->service->moveStock(
            (int) $user['organisation_id'],
            (int) $user['id'],
            (int) $params['id'],
            $payload
        );
    }

    public function report(Request $request): array
    {
        $user = $this->tenantUser('reports.read');
        $today = new DateTimeImmutable('today');
        $from = $this->date($request->query['from'] ?? $today->modify('-29 days')->format('Y-m-d'), 'from');
        $to = $this->date($request->query['to'] ?? $today->format('Y-m-d'), 'to');
        if ($from > $to) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'from' => 'The start date must be on or before the end date.'
            ]);
        }
        return $this->service->report((int) $user['organisation_id'], $from, $to);
    }

    private function tenantUser(string $permission): array
    {
        $user = $this->middleware->requireUser();
        if ($user['organisation_id'] === null) {
            throw new ApiException(403, 'tenant_required', 'This operation requires an organisation account.');
        }
        if (!$this->roles->hasPermission((int) $user['id'], $permission)
            && !$this->roles->hasPermission((int) $user['id'], 'platform.users.manage')) {
            throw new ApiException(403, 'forbidden', 'You do not have permission to access this business operation.');
        }
        return $user;
    }

    private function pagination(Request $request): array
    {
        $limit = filter_var($request->query['limit'] ?? 50, FILTER_VALIDATE_INT);
        $offset = filter_var($request->query['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100 || $offset === false || $offset < 0) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'pagination' => 'Use a limit from 1 to 100 and a non-negative offset.'
            ]);
        }
        return [$limit, $offset];
    }

    private function money(array $input, string $field, bool $mustBePositive = false): string
    {
        $value = $input[$field] ?? null;
        if (is_float($value) && is_finite($value)) {
            $value = number_format($value, 2, '.', '');
        } elseif (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\\.[0-9]{1,2})?$/', $value)
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
