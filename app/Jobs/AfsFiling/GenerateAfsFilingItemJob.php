<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Models\AfsFilingItem;
use App\Models\FilingOutput;
use App\Services\AfsFiling\AfsFilingItemGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAfsFilingItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Must exceed the LibreOffice process timeout (120s) so the process
    // timeout error is caught inside generate() before the worker is killed.
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $afsFilingItemId,
    ) {
        $this->onQueue('afs-filing');
    }

    public function handle(AfsFilingItemGenerationService $generationService): void
    {
        $generationService->generate($this->afsFilingItemId);
    }

    // Called when the job is killed by a timeout or exhausts tries.
    // Ensures the item never stays stuck in queued/processing.
    public function failed(Throwable $exception): void
    {
        $item = AfsFilingItem::query()->find($this->afsFilingItemId);
        if (! $item instanceof AfsFilingItem) {
            return;
        }

        if (in_array((string) $item->status, ['pdf_done', 'failed'], true)) {
            return;
        }

        $item->status = 'failed';
        $item->error_message = mb_substr($this->toUserFriendlyError($exception), 0, 2000);
        $item->completed_at = now();
        $item->save();

        $rowData = is_array($item->row_data) ? $item->row_data : [];
        $filingOutputId = (int) ($rowData['__filing_output_id'] ?? 0);
        if ($filingOutputId > 0) {
            $filingOutput = FilingOutput::query()->find($filingOutputId);
            if ($filingOutput instanceof FilingOutput) {
                $filingOutput->status = 'failed';
                $filingOutput->error_message = $item->error_message;
                $filingOutput->save();
            }
        }
    }

    private function toUserFriendlyError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        $normalized = mb_strtolower($message);

        if (str_contains($normalized, 'attempted too many times')) {
            return 'Generation failed after multiple retry attempts. Please retry this row.';
        }

        if (str_contains($normalized, 'timed out')) {
            return 'Generation timed out while processing this row. Please retry.';
        }

        return $message !== '' ? $message : 'Generation failed. Please retry this row.';
    }
}
