<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\AfsFiling\DeleteAfsFilingItemJob;
use App\Models\AfsFilingItem;
use App\Models\User;
use App\Support\DocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteAfsCompletedItemJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_deletes_eligible_row_and_files(): void
    {
        Storage::fake(DocumentStorage::diskName());

        $user = User::factory()->create();

        $docxPath = "afs_filing/{$user->id}/rows/sample.docx";
        $pdfPath = "afs_filing/{$user->id}/rows/sample.pdf";
        Storage::disk(DocumentStorage::diskName())->put($docxPath, 'docx');
        Storage::disk(DocumentStorage::diskName())->put($pdfPath, 'pdf');

        $item = AfsFilingItem::query()->create([
            'user_id' => (int) $user->getKey(),
            'row_number' => 2,
            'row_data' => ['COMPANY' => 'Acme'],
            'status' => 'pdf_done',
            'signature_applied_at' => now(),
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
        ]);

        (new DeleteAfsFilingItemJob((int) $user->getKey(), (int) $item->getKey()))->handle();

        $this->assertSoftDeleted('afs_filing_items', ['id' => (int) $item->getKey()]);
        Storage::disk(DocumentStorage::diskName())->assertMissing($docxPath);
        Storage::disk(DocumentStorage::diskName())->assertMissing($pdfPath);
    }

    public function test_job_is_idempotent_when_row_missing_or_owned_by_another_user(): void
    {
        Storage::fake(DocumentStorage::diskName());

        $user = User::factory()->create();

        $owner = User::factory()->create();
        $otherUsersItem = AfsFilingItem::query()->create([
            'user_id' => (int) $owner->getKey(),
            'row_number' => 2,
            'row_data' => ['COMPANY' => 'Acme'],
            'status' => 'queued',
            'signature_applied_at' => null,
        ]);

        (new DeleteAfsFilingItemJob((int) $user->getKey(), (int) $otherUsersItem->getKey()))->handle();
        (new DeleteAfsFilingItemJob((int) $user->getKey(), 999999))->handle();

        $this->assertDatabaseHas('afs_filing_items', [
            'id' => (int) $otherUsersItem->getKey(),
            'deleted_at' => null,
        ]);
    }

    public function test_job_deletes_even_when_item_is_not_pdf_done(): void
    {
        Storage::fake(DocumentStorage::diskName());

        $user = User::factory()->create();

        $notEligible = AfsFilingItem::query()->create([
            'user_id' => (int) $user->getKey(),
            'row_number' => 2,
            'row_data' => ['COMPANY' => 'Acme'],
            'status' => 'processing',
            'signature_applied_at' => null,
        ]);

        (new DeleteAfsFilingItemJob((int) $user->getKey(), (int) $notEligible->getKey()))->handle();

        $this->assertSoftDeleted('afs_filing_items', ['id' => (int) $notEligible->getKey()]);
    }

    public function test_job_targets_afs_filing_queue(): void
    {
        $job = new DeleteAfsFilingItemJob(1, 1);

        $this->assertSame('afs-filing', $job->queue);
    }
}
