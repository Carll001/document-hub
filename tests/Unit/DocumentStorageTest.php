<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentStorageTest extends TestCase
{
    public function test_disk_name_comes_from_default_filesystem_config(): void
    {
        config()->set('filesystems.default', 'rustfs');

        $this->assertSame('rustfs', DocumentStorage::diskName());
    }

    public function test_disk_uses_configured_default_filesystem_disk(): void
    {
        Storage::fake('rustfs');
        config()->set('filesystems.default', 'rustfs');

        DocumentStorage::disk()->put('document-storage/probe.txt', 'ok');

        Storage::disk('rustfs')->assertExists('document-storage/probe.txt');
    }
}
