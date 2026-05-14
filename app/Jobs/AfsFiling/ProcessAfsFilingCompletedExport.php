<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Models\AfsFilingItem;
use App\Models\User;
use App\Services\DocumentGeneratorCompletedExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAfsFilingCompletedExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param list<int> $itemIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $itemIds,
    ) {
        $this->onQueue('afs-filing');
    }

    public function handle(DocumentGeneratorCompletedExportService $completedExportService): void
    {
        $user = User::query()->find($this->userId);
        if (! $user instanceof User) {
            return;
        }

        $cancelRequested = $completedExportService->cancellationRequested($this->userId);

        if ($cancelRequested) {
            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                'error' => null,
                'itemCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
                'cancelRequested' => false,
            ]);

            return;
        }

        $completedExportService->putState($this->userId, [
            'status' => DocumentGeneratorCompletedExportService::STATUS_PROCESSING,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
            'cancelRequested' => $cancelRequested,
        ]);

        try {
            $itemsQuery = AfsFilingItem::query()
                ->where('user_id', (int) $user->getKey())
                ->whereIn('id', $this->itemIds)
                ->whereNotNull('pdf_path');

            if (! (clone $itemsQuery)->exists()) {
                throw new \RuntimeException('No completed files matched this export request.');
            }

            $export = $completedExportService->buildZipFromQuery($itemsQuery, $this->userId, 10);

            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_READY,
                'error' => null,
                'itemCount' => $export['itemCount'],
                'downloadUrl' => route('afs-filing.completed.download.file'),
                'downloadFileName' => sprintf('AFS_COMPLETED_%s.zip', now()->format('Ymd_His')),
                'storagePath' => $export['storagePath'],
                'expiresAt' => now()->addSeconds(30)->toIso8601String(),
            ]);
        } catch (\Throwable $exception) {
            if ($exception->getMessage() === DocumentGeneratorCompletedExportService::CANCEL_MESSAGE) {
                $completedExportService->putState($this->userId, [
                    'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                    'error' => null,
                    'itemCount' => null,
                    'downloadUrl' => null,
                    'storagePath' => null,
                    'cancelRequested' => false,
                ]);

                return;
            }

            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'The completed files ZIP could not be prepared right now.',
                'itemCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
                'cancelRequested' => false,
            ]);

            throw $exception;
        }
    }
}
