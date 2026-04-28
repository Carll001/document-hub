<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Models\AfsFilingItem;
use App\Models\User;
use App\Services\AfsFiling\AfsFilingItemSigningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
            $item->status = 'pdf_done';
            $item->error_message = 'Unable to apply signature: owning user not found.';
            $item->save();

            return;
        }

        try {
            $signingService->sign($item, $user, $this->presidentSignaturePath);
            $item->error_message = null;
            $item->error_details = null;
            $item->save();
        } catch (\Throwable $exception) {
            $item->status = 'pdf_done';
            $item->error_message = $exception->getMessage();
            $item->error_details = [
                'stage' => 'signing',
                'exception' => $exception::class,
            ];
            $item->save();
        }
    }
}

