<?php
declare(strict_types=1);

namespace Vantiq\Service;

use Vantiq\Repository\LedgerRepository;

final class LedgerService
{
    public function __construct(private readonly LedgerRepository $repository)
    {
    }

    public function products(int $organisationId): array
    {
        return $this->repository->products($organisationId);
    }

    public function createProduct(int $organisationId, int $userId, array $input): array
    {
        return $this->repository->createProduct($organisationId, $userId, $input);
    }

    public function createSale(int $organisationId, int $userId, array $input): array
    {
        return $this->repository->createSale($organisationId, $userId, $input);
    }

    public function sales(int $organisationId, int $limit, int $offset): array
    {
        return $this->repository->sales($organisationId, $limit, $offset);
    }

    public function refundSale(int $organisationId, int $userId, int $saleId, string $reason): array
    {
        return $this->repository->refundSale($organisationId, $userId, $saleId, $reason);
    }

    public function createExpense(int $organisationId, int $userId, array $input): array
    {
        return $this->repository->createExpense($organisationId, $userId, $input);
    }

    public function expenses(int $organisationId, int $limit, int $offset): array
    {
        return $this->repository->expenses($organisationId, $limit, $offset);
    }

    public function moveStock(int $organisationId, int $userId, int $productId, array $input): array
    {
        return $this->repository->moveStock($organisationId, $userId, $productId, $input);
    }

    public function report(int $organisationId, string $from, string $to): array
    {
        return $this->repository->report($organisationId, $from, $to);
    }
}
