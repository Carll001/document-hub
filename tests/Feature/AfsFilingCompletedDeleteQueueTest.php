<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AfsFiling\DeleteAfsFilingItemJob;
use App\Models\AfsFilingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AfsFilingCompletedDeleteQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_delete_queues_one_row_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $item = AfsFilingItem::query()->create([
            'user_id' => (int) $user->getKey(),
            'row_number' => 2,
            'row_data' => ['COMPANY' => 'Acme Inc.'],
            'status' => 'pdf_done',
            'signature_applied_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->deleteJson(route('afs-filing.completed.items.destroy.bulk'), [
                'item_ids' => [(int) $item->getKey()],
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'queued_count' => 1,
            ]);

        Queue::assertPushed(DeleteAfsFilingItemJob::class, 1);
        Queue::assertPushed(DeleteAfsFilingItemJob::class, function (DeleteAfsFilingItemJob $job) use ($user, $item): bool {
            return $job->userId === (int) $user->getKey() && $job->itemId === (int) $item->getKey();
        });
    }

    public function test_selected_delete_queues_only_valid_rows(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $eligible = AfsFilingItem::query()->create([
            'user_id' => (int) $user->getKey(),
            'row_number' => 2,
            'row_data' => ['COMPANY' => 'Eligible 1'],
            'status' => 'pdf_done',
            'signature_applied_at' => now(),
        ]);

        $eligibleTwo = AfsFilingItem::query()->create([
            'user_id' => (int) $user->getKey(),
            'row_number' => 3,
            'row_data' => ['COMPANY' => 'Eligible 2'],
            'status' => 'pdf_done',
            'signature_applied_at' => now(),
        ]);

        $notSigned = AfsFilingItem::query()->create([
            'user_id' => (int) $user->getKey(),
            'row_number' => 4,
            'row_data' => ['COMPANY' => 'Not signed'],
            'status' => 'pdf_done',
            'signature_applied_at' => null,
        ]);

        $otherUsersItem = AfsFilingItem::query()->create([
            'user_id' => (int) $otherUser->getKey(),
            'row_number' => 5,
            'row_data' => ['COMPANY' => 'Other user'],
            'status' => 'pdf_done',
            'signature_applied_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->deleteJson(route('afs-filing.completed.items.destroy.bulk'), [
                'item_ids' => [
                    (int) $eligible->getKey(),
                    (int) $eligibleTwo->getKey(),
                    (int) $notSigned->getKey(),
                    (int) $otherUsersItem->getKey(),
                    999999,
                ],
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'queued_count' => 2,
            ]);

        Queue::assertPushed(DeleteAfsFilingItemJob::class, 2);
    }
}
