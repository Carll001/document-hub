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

        $completedExportService->putState($this->userId, [
            'status' => DocumentGeneratorCompletedExportService::STATUS_PROCESSING,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
        ]);

        try {
            $items = AfsFilingItem::query()
                ->where('user_id', (int) $user->getKey())
                ->whereIn('id', $this->itemIds)
                ->where('status', 'pdf_done')
                ->whereNotNull('signature_applied_at')
                ->get();

            if ($items->isEmpty()) {
                throw new \RuntimeException('No completed files matched this export request.');
            }

            $export = $completedExportService->buildZip($items, $this->userId);

            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_READY,
                'error' => null,
                'itemCount' => $export['itemCount'],
                'downloadUrl' => route('afs-filing.completed.download.file'),
                'storagePath' => $export['storagePath'],
            ]);
        } catch (\Throwable $exception) {
            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'The completed files ZIP could not be prepared right now.',
                'itemCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
            ]);

            throw $exception;
        }
    }
}
