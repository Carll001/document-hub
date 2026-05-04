<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Models\AfsFilingItem;
use App\Models\FilingOutput;
use App\Models\User;
use App\Services\AfsFiling\AfsFilingItemSigningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ApplyAfsFilingItemSignatureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        public readonly int $itemId,
        public readonly string $presidentSignaturePath,
    ) {
        $this->onQueue('afs-filing');
    }

    public function handle(AfsFilingItemSigningService $signingService): void
    {
        $item = AfsFilingItem::query()
            ->where('id', $this->itemId)
            ->where('user_id', $this->userId)
            ->first();

        if (! $item instanceof AfsFilingItem) {
            return;
        }

        if ((string) $item->status !== 'signing' || $item->signature_applied_at !== null) {
            return;
        }

        $user = User::query()->find($this->userId);
        if (! $user instanceof User) {
            $item->status = 'generated';
            $item->error_message = 'Unable to apply signature: owning user not found.';
            $item->save();
            $this->syncFilingOutput($item, 'generated', $item->error_message);

            return;
        }

        try {
            $signingService->sign($item, $user, $this->presidentSignaturePath);
            $item->error_message = null;
            $item->error_details = null;
            $item->save();
            Log::info('AFS signing job completed.', [
                'item_id' => (int) $item->id,
                'user_id' => (int) $this->userId,
                'status' => (string) $item->status,
            ]);
        } catch (\Throwable $exception) {
            Log::error('AFS signing job failed.', [
                'item_id' => (int) $item->id,
                'user_id' => (int) $this->userId,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            $item->status = 'generated';
            $item->error_message = $exception->getMessage();
            $item->error_details = [
                'stage' => 'signing',
                'exception' => $exception::class,
            ];
            $item->save();
            $this->syncFilingOutput($item, 'generated', $item->error_message);
        }
    }

    private function syncFilingOutput(AfsFilingItem $item, string $status, ?string $errorMessage): void
    {
        $rowData = is_array($item->row_data) ? $item->row_data : [];
        $filingOutputId = (int) ($rowData['__filing_output_id'] ?? 0);
        if ($filingOutputId <= 0) {
            return;
        }

        $output = FilingOutput::query()->find($filingOutputId);
        if (! $output instanceof FilingOutput) {
            return;
        }

        $output->status = $status;
        $output->error_message = $errorMessage;
        $output->save();
    }
}
