<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConfirmationReceiptSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_confirmation_receipt_settings(): void
    {
        $this->withoutVite();
        $this->useTemporaryPublicPath();

        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)
            ->get(route('confirmation-template.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/ConfirmationTemplate')
                ->where('receiptTemplate.activePdfPath', 'public/form-assets/1702-ex/template-receipt-fpdi.pdf')
                ->where('receiptTemplate.fallbackPdfPath', 'public/form-assets/1702-ex/template-receipt.pdf')
                ->where('receiptTemplate.schemaPath', 'resources/forms/templates/1702-ex/receipt.schema.json'));
    }

    public function test_staff_can_replace_the_confirmation_receipt_pdf(): void
    {
        $publicPath = $this->useTemporaryPublicPath();
        $targetPath = $publicPath.'/form-assets/1702-ex/template-receipt-fpdi.pdf';
        $replacementPdf = "%PDF-1.4\nreplacement receipt\n%%EOF";
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)
            ->post(route('confirmation-template.update'), [
                'receipt_template' => UploadedFile::fake()
                    ->createWithContent('replacement-receipt.pdf', $replacementPdf),
            ])
            ->assertRedirect(route('confirmation-template.edit'))
            ->assertSessionHas('success', 'Confirmation receipt PDF updated.');

        $this->assertSame($replacementPdf, File::get($targetPath));
    }

    private function useTemporaryPublicPath(): string
    {
        $publicPath = storage_path('framework/testing/public-'.str()->uuid());
        $assetPath = $publicPath.'/form-assets/1702-ex';

        File::ensureDirectoryExists($assetPath);
        File::put($assetPath.'/template-receipt-fpdi.pdf', "%PDF-1.4\ncurrent\n%%EOF");
        File::put($assetPath.'/template-receipt.pdf', "%PDF-1.4\nfallback\n%%EOF");

        $this->app->usePublicPath($publicPath);

        return $publicPath;
    }
}
