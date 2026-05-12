<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DocumentBatchItem;
use App\Models\User;
use App\Services\DocumentGeneratorCompletedExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

class ProcessDocumentGeneratorCompletedExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  list<int>  $itemIds
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
            $query = DocumentBatchItem::query()
                ->whereIn('id', $this->itemIds)
                ->where('status', 'pdf_done')
                ->whereHas('batch', static function ($batchQuery) use ($user): void {
                    $batchQuery->where('user_id', $user->id);
                });

            if ($this->supportsItemSignatureAppliedAt()) {
                $query->whereNotNull('signature_applied_at');
            }

            $items = $query->get();

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
                'error' => $this->runtimeMessage($exception),
                'itemCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
            ]);

            throw $exception;
        }
    }

    private function supportsItemSignatureAppliedAt(): bool
    {
        static $supportsSignatureAppliedAt = null;

        if ($supportsSignatureAppliedAt !== null) {
            return $supportsSignatureAppliedAt;
        }

        $supportsSignatureAppliedAt = Schema::hasColumn('document_batch_items', 'signature_applied_at');

        return $supportsSignatureAppliedAt;
    }

    private function runtimeMessage(\Throwable $exception): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'The completed files ZIP could not be prepared right now.';
    }
}
