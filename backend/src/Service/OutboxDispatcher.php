<?php
declare(strict_types=1);

namespace Vantiq\Service;

use Throwable;
use Vantiq\Infrastructure\Logger;
use Vantiq\Repository\OutboxRepository;

final class OutboxDispatcher
{
    private const PRESENTATION = [
        'SaleCreated' => ['A sale was recorded.', ['command', 'overview', 'finance', 'operations', 'reports', 'forecast']],
        'SaleCancelled' => ['A sale was cancelled.', ['command', 'overview', 'finance', 'operations', 'reports', 'forecast']],
        'ExpenseCreated' => ['An expense was recorded.', ['command', 'overview', 'finance', 'risk', 'reports', 'forecast']],
        'ProductCreated' => ['A product was added.', ['command', 'overview', 'operations', 'forecast']],
        'StockAdjusted' => ['Inventory levels changed.', ['command', 'overview', 'operations', 'risk', 'forecast']],
        'StockReceived' => ['Inventory was received.', ['command', 'overview', 'operations', 'risk', 'forecast']],
        'InvoiceIssued' => ['An invoice was issued.', ['command', 'overview', 'finance', 'reports']],
        'InvoicePaid' => ['An invoice payment was recorded.', ['command', 'overview', 'finance', 'reports', 'risk']],
        'InvoicePaymentRecorded' => ['An invoice payment was recorded.', ['command', 'overview', 'finance', 'reports', 'risk']],
        'CustomerCreated' => ['A customer was added.', ['command', 'overview', 'finance', 'reports']],
        'SupplierCreated' => ['A supplier was added.', ['command', 'overview', 'operations']],
        'PurchaseOrderCreated' => ['A purchase order was created.', ['command', 'overview', 'operations']],
        'PurchaseOrderSubmitted' => ['A purchase order was submitted.', ['command', 'overview', 'operations']],
        'PurchaseOrderReceived' => ['A purchase order was received.', ['command', 'overview', 'operations', 'risk', 'forecast']],
        'DocumentUploaded' => ['A business document was uploaded.', ['connect', 'control']],
        'DocumentAnalysisCompleted' => ['Document text extraction completed.', ['connect', 'control']],
        'DocumentReviewed' => ['A business document review was completed.', ['connect', 'control']],
        'ImportCommitted' => ['Approved business information was imported.', ['command', 'overview', 'finance', 'operations', 'reports', 'forecast']],
    ];

    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly Logger $logger
    ) {
    }

    public function runOnce(int $limit = 50, ?int $organisationId = null): array
    {
        $processed = 0;
        $failed = 0;
        while ($processed + $failed < max(1, min($limit, 500))) {
            $event = $this->outbox->claimNext($organisationId);
            if ($event === null) {
                break;
            }

            try {
                [$summary, $modules] = self::PRESENTATION[$event['event_type']]
                    ?? ['Business information was updated.', ['command', 'overview']];
                $this->outbox->publish($event, $summary, $modules);
                $processed++;
            } catch (Throwable $exception) {
                $referenceId = $this->logger->exception(
                    'Outbox event processing failed',
                    $exception,
                    [
                        'operation' => 'outbox.dispatch',
                        'stage' => 'event_processing',
                        'event_id' => $event['event_id'],
                        'event_type' => $event['event_type'],
                        'organisation_id' => (int) $event['organisation_id'],
                    ]
                );
                $this->outbox->fail($event, $referenceId);
                $failed++;
            }
        }

        return ['published' => $processed, 'failed' => $failed];
    }
}
