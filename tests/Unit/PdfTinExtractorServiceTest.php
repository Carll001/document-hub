<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PdfTinExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfTinExtractorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_extracts_the_first_labeled_tin_number_from_text(): void
    {
        $service = app(PdfTinExtractorService::class);

        $tinNumber = $service->extractTinNumberFromText(implode("\n", [
            'Some heading',
            'Taxpayer Identification Number (TIN): 123-456-789-000',
            'TIN: 999-999-999-999',
        ]));

        $this->assertSame('123-456-789-000', $tinNumber);
    }

    public function test_it_matches_supported_tin_labels_case_insensitively(): void
    {
        $service = app(PdfTinExtractorService::class);

        $this->assertSame(
            '123 456 789 000',
            $service->extractTinNumberFromText(
                'tax identification number 123 456 789 000',
            ),
        );
        $this->assertSame(
            '123-456-789-000',
            $service->extractTinNumberFromText('T.I.N. 123-456-789-000'),
        );
    }

    public function test_it_ignores_unlabeled_number_strings(): void
    {
        $service = app(PdfTinExtractorService::class);

        $tinNumber = $service->extractTinNumberFromText(implode("\n", [
            'Reference number: 123-456-789-000',
            'Account code: 000111222333',
            'No TIN label is shown here.',
        ]));

        $this->assertNull($tinNumber);
    }

    public function test_it_extracts_bir_style_tin_values_split_into_nearby_boxes(): void
    {
        $service = app(PdfTinExtractorService::class);

        $tinNumber = $service->extractTinNumberFromText(implode("\n", [
            'Background Information',
            '6 Taxpayer Identification Number (TIN)',
            '010 832 707 0000',
        ]));

        $this->assertSame('010-832-707-0000', $tinNumber);
    }

    public function test_it_extracts_bir_style_tin_values_when_the_groups_are_on_one_line(): void
    {
        $service = app(PdfTinExtractorService::class);

        $tinNumber = $service->extractTinNumberFromText(
            '6 Taxpayer Identification Number (TIN) 010 832 707 0000',
        );

        $this->assertSame('010-832-707-0000', $tinNumber);
    }

    public function test_it_extracts_tin_values_when_other_field_text_sits_between_the_label_and_number(): void
    {
        $service = app(PdfTinExtractorService::class);

        $tinNumber = $service->extractTinNumberFromText(implode("\n", [
            'Background Information',
            "      6Taxpayer Identification Number (TIN)- - -\t7RDO Code21B ",
            '      010 832 707 0000',
        ]));

        $this->assertSame('010-832-707-0000', $tinNumber);
    }
}
