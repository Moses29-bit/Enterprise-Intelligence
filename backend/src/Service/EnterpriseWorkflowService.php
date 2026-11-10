<?php
declare(strict_types=1);

namespace Vantiq\Service;

use Vantiq\Repository\EnterpriseWorkflowRepository;

final class EnterpriseWorkflowService
{
    public function __construct(private readonly EnterpriseWorkflowRepository $repository)
    {
    }

    public function invoices(int $organisationId): array
    {
        return $this->repository->invoices($organisationId);
    }

    public function invoiceDetail(int $organisationId, int $invoiceId): array
    {
        return $this->repository->invoiceDetail($organisationId, $invoiceId);
    }

    public function customers(int $organisationId): array
    {
        return $this->repository->customers($organisationId);
    }

    public function createCustomer(int $organisationId, int $userId, string $name, ?string $email): array
    {
        return $this->repository->createCustomer($organisationId, $userId, $name, $email);
    }

    public function createInvoice(int $organisationId, int $userId, array $input): array
    {
        return $this->repository->createInvoice($organisationId, $userId, $input);
    }

    public function recordPayment(int $organisationId, int $userId, int $invoiceId, array $input): array
    {
        return $this->repository->recordPayment($organisationId, $userId, $invoiceId, $input);
    }

    public function creditNotes(int $organisationId, int $invoiceId): array
    {
        return $this->repository->creditNotes($organisationId, $invoiceId);
    }

    public function createCreditNote(int $organisationId, int $userId, int $invoiceId, array $input): array
    {
        return $this->repository->createCreditNote($organisationId, $userId, $invoiceId, $input);
    }

    public function suppliers(int $organisationId): array
    {
        return $this->repository->suppliers($organisationId);
    }

    public function createSupplier(int $organisationId, int $userId, string $name, ?string $email): array
    {
        return $this->repository->createSupplier($organisationId, $userId, $name, $email);
    }

    public function purchaseOrders(int $organisationId): array
    {
        return $this->repository->purchaseOrders($organisationId);
    }

    public function createPurchaseOrder(int $organisationId, int $userId, array $input): array
    {
        return $this->repository->createPurchaseOrder($organisationId, $userId, $input);
    }

    public function submitPurchaseOrder(int $organisationId, int $userId, int $orderId): array
    {
        return $this->repository->submitPurchaseOrder($organisationId, $userId, $orderId);
    }

    public function receivePurchaseOrder(int $organisationId, int $userId, int $orderId): array
    {
        return $this->repository->receivePurchaseOrder($organisationId, $userId, $orderId);
    }
}
