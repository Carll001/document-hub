<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Models\AfsFilingItem;
use App\Services\DocumentGeneratorCompletedExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeAfsCompletedExportBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $userId,
        public readonly string $batchId,
        public readonly string $context = 'index',
    ) {}

    public function handle(DocumentGeneratorCompletedExportService $completedExportService): void
    {
        $state = $completedExportService->rawState($this->userId, $this->context);
        if (! is_array($state)) {
            return;
        }

        $stateBatchId = is_string($state['batchId'] ?? null) ? (string) $state['batchId'] : null;
        if ($stateBatchId === null || $stateBatchId !== $this->batchId) {
            return;
        }

        $sourceItemIds = collect($state['sourceItemIds'] ?? [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($sourceItemIds === []) {
            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                'error' => 'No completed files matched this export request.',
                'itemCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
                'cancelRequested' => false,
                'batchId' => $this->batchId,
            ], $this->context);

            return;
        }

        try {
            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_PROCESSING,
                'error' => null,
                'itemCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
                'cancelRequested' => false,
                'batchId' => $this->batchId,
                'sourceItemIds' => $sourceItemIds,
            ], $this->context);

            $itemsQuery = AfsFilingItem::query()
                ->where('user_id', $this->userId)
                ->whereIn('id', $sourceItemIds)
                ->whereNotNull('pdf_path');

            if (! (clone $itemsQuery)->exists()) {
                throw new \RuntimeException('No completed files matched this export request.');
            }

            $export = $completedExportService->buildZipFromQuery($itemsQuery, $this->userId, 25, $this->context);

            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_READY,
                'error' => null,
                'itemCount' => $export['itemCount'],
                'downloadUrl' => route('afs-filing.completed.download.file', ['context' => $this->context]),
                'downloadFileName' => sprintf('AFS_COMPLETED_%s.zip', now()->format('Ymd_His')),
                'storagePath' => $export['storagePath'],
                'cancelRequested' => false,
                'batchId' => $this->batchId,
            ], $this->context);
        } catch (\Throwable $exception) {
            if ($exception->getMessage() === DocumentGeneratorCompletedExportService::CANCEL_MESSAGE) {
                $completedExportService->putState($this->userId, [
                    'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                    'error' => null,
                    'itemCount' => null,
                    'downloadUrl' => null,
                    'storagePath' => null,
                    'cancelRequested' => false,
                    'batchId' => $this->batchId,
                ], $this->context);

                return;
            }

            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'The completed files ZIP could not be prepared right now.',
                'itemCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
                'cancelRequested' => false,
                'batchId' => $this->batchId,
            ], $this->context);
        }
    }
}
