<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\ProcessForm1702ExBatchImport;
use App\Models\Form1702ExBatch;
use App\Models\User;
use App\Services\Form1702ExBatchService;
use App\Services\Form1702ExImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessForm1702ExBatchImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_fails_with_controlled_message_when_import_source_path_is_zero(): void
    {
        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Queued Import',
            'import_status' => Form1702ExBatch::IMPORT_STATUS_QUEUED,
            'import_source_path' => '0',
            'import_source_name' => 'invalid.csv',
        ]);

        $job = new ProcessForm1702ExBatchImport((int) $batch->id);

        try {
            $job->handle(
                app(Form1702ExBatchService::class),
                app(Form1702ExImportService::class),
            );
            $this->fail('Expected runtime exception for invalid import source path.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'The uploaded spreadsheet could not be found. Please upload it again.',
                $exception->getMessage(),
            );
        }

        $batch->refresh();
        $this->assertSame(Form1702ExBatch::IMPORT_STATUS_FAILED, $batch->import_status);
        $this->assertSame(
            'The uploaded spreadsheet could not be found. Please upload it again.',
            $batch->import_error,
        );
        $this->assertNull($batch->import_source_path);
    }
}
