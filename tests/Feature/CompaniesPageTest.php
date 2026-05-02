<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CompaniesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_staff_can_view_companies_index_page(): void
    {
        $user = User::factory()->create([
            'role' => 'staff',
        ]);

        $this->actingAs($user)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('companies/Index')
                    ->has('companies.data')
                    ->has('companies.pagination')
                    ->has('stats')
                    ->has('filters'),
            );
    }

    public function test_staff_can_import_companies_and_upsert_globally_by_name_and_tin(): void
    {
        $firstUser = User::factory()->create(['role' => 'staff']);
        $secondUser = User::factory()->create(['role' => 'staff']);

        $existing = Company::query()->create([
            'user_id' => $firstUser->id,
            'client_id' => null,
            'name' => 'ACME Corp',
            'name_normalized' => 'acme corp',
            'tin' => '123-456-789-000',
            'tin_normalized' => '123456789000',
            'address' => 'Old address',
            'imported_via_excel' => false,
        ]);

        $file = $this->makeCompanyImportFile([
            ['name', 'tin', 'address'],
            ['ACME Corp', '123-456-789-000', 'New address'],
            ['New Global Co', '987-654-321-000', 'Pasig City'],
        ]);

        $this->actingAs($secondUser)
            ->post(route('companies.import'), [
                'spreadsheet' => $file,
                'overwrite_existing' => true,
            ])
            ->assertRedirect(route('companies.index'));

        $existing->refresh();

        $this->assertSame('New address', $existing->address);
        $this->assertTrue((bool) $existing->imported_via_excel);

        $this->assertDatabaseHas('companies', [
            'name_normalized' => 'new global co',
            'tin_normalized' => '987654321000',
        ]);

        $this->assertSame(2, Company::query()->count());
    }

    public function test_staff_can_import_companies_with_alias_headers_and_store_unknown_columns_in_data(): void
    {
        $firstUser = User::factory()->create(['role' => 'staff']);
        $secondUser = User::factory()->create(['role' => 'staff']);

        $existing = Company::query()->create([
            'user_id' => $firstUser->id,
            'client_id' => null,
            'name' => 'ACME Corp',
            'name_normalized' => 'acme corp',
            'tin' => '123-456-789-000',
            'tin_normalized' => '123456789000',
            'address' => 'Old address',
            'data' => ['legacyfield' => 'legacy value'],
            'imported_via_excel' => false,
        ]);

        $file = $this->makeCompanyImportFile([
            ['COMPANY NAME', 'COMPANY TIN', 'registered_address', 'Stakeholder', 'Favorite Color'],
            ['ACME Corp', '123-456-789-000', 'New address', 'Alice', 'Blue'],
            ['New Global Co', '987-654-321-000', 'Pasig City', 'Bob', 'Green'],
        ]);

        $this->actingAs($secondUser)
            ->post(route('companies.import'), [
                'spreadsheet' => $file,
                'overwrite_existing' => true,
            ])
            ->assertRedirect(route('companies.index'));

        $existing->refresh();

        $this->assertSame('New address', $existing->address);
        $this->assertTrue((bool) $existing->imported_via_excel);
        $this->assertSame([
            'legacyfield' => 'legacy value',
            'stakeholder' => 'Alice',
            'favoritecolor' => 'Blue',
        ], $existing->data);

        $created = Company::query()
            ->where('name_normalized', 'new global co')
            ->where('tin_normalized', '987654321000')
            ->first();

        $this->assertInstanceOf(Company::class, $created);
        $this->assertSame([
            'stakeholder' => 'Bob',
            'favoritecolor' => 'Green',
        ], $created->data);
    }

    public function test_staff_import_skips_rows_without_required_name_or_tin_even_with_alias_headers(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $file = $this->makeCompanyImportFile([
            ['registered_name', 'company_tin', 'stakeholder'],
            ['', '123-456-789-000', 'Alice'],
            ['No Tin Co', '', 'Bob'],
            ['Valid Co', '111-222-333-444', 'Carol'],
        ]);

        $this->actingAs($user)
            ->post(route('companies.import'), [
                'spreadsheet' => $file,
                'overwrite_existing' => true,
            ])
            ->assertRedirect(route('companies.index'));

        $this->assertSame(1, Company::query()->count());

        $company = Company::query()->first();

        $this->assertInstanceOf(Company::class, $company);
        $this->assertSame('Valid Co', $company->name);
        $this->assertSame('111222333444', $company->tin_normalized);
        $this->assertSame(['stakeholder' => 'Carol'], $company->data);
    }

    public function test_staff_can_import_companies_and_keep_existing_when_overwrite_is_disabled(): void
    {
        $firstUser = User::factory()->create(['role' => 'staff']);
        $secondUser = User::factory()->create(['role' => 'staff']);

        $existing = Company::query()->create([
            'user_id' => $firstUser->id,
            'client_id' => null,
            'name' => 'ACME Corp',
            'name_normalized' => 'acme corp',
            'tin' => '123-456-789-000',
            'tin_normalized' => '123456789000',
            'address' => 'Old address',
            'data' => ['legacyfield' => 'legacy value'],
            'imported_via_excel' => false,
        ]);

        $file = $this->makeCompanyImportFile([
            ['name', 'tin', 'address', 'Stakeholder'],
            ['ACME Corp', '123-456-789-000', 'New address', 'Alice'],
            ['New Global Co', '987-654-321-000', 'Pasig City', 'Bob'],
        ]);

        $this->actingAs($secondUser)
            ->post(route('companies.import'), [
                'spreadsheet' => $file,
                'overwrite_existing' => false,
            ])
            ->assertRedirect(route('companies.index'));

        $existing->refresh();

        $this->assertSame('Old address', $existing->address);
        $this->assertSame(['legacyfield' => 'legacy value'], $existing->data);
        $this->assertFalse((bool) $existing->imported_via_excel);

        $this->assertDatabaseHas('companies', [
            'name_normalized' => 'new global co',
            'tin_normalized' => '987654321000',
            'address' => 'Pasig City',
        ]);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function makeCompanyImportFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $columns) {
            foreach ($columns as $columnIndex => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1);
                $worksheet->setCellValue($coordinate, $value);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'company-import-');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
        $contents = file_get_contents($tempPath);
        @unlink($tempPath);

        if (! is_string($contents)) {
            $this->fail('Failed to build import spreadsheet test file.');
        }

        return UploadedFile::fake()->createWithContent(
            'companies-import.xlsx',
            $contents,
        );
    }
}
