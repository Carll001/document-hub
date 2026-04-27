<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FormFieldAliasResolver;
use Tests\TestCase;

class FormFieldAliasResolverTest extends TestCase
{
    public function test_it_resolves_tin_using_form_specific_aliases(): void
    {
        $rowData = [
            'COMPANY TIN' => '123-456-789-000',
        ];

        $this->assertSame(
            '123-456-789-000',
            FormFieldAliasResolver::resolveAliasedField(
                $rowData,
                'tin',
                FormFieldAliasResolver::FORM_AFS,
            ),
        );
    }

    public function test_it_resolves_tin_using_global_aliases_for_other_forms(): void
    {
        $rowData = [
            'Tax Identification Number' => '987-654-321-000',
        ];

        $this->assertSame(
            '987-654-321-000',
            FormFieldAliasResolver::resolveAliasedField(
                $rowData,
                'tin',
                FormFieldAliasResolver::FORM_2551Q,
            ),
        );
    }

    public function test_it_normalizes_tin_to_digits_only(): void
    {
        $rowData = [
            'TIN' => '123-456-789-000',
        ];

        $this->assertSame(
            '123456789000',
            FormFieldAliasResolver::resolveTin(
                $rowData,
                FormFieldAliasResolver::FORM_1702EX,
            ),
        );
    }

    public function test_it_resolves_company_using_global_aliases(): void
    {
        $rowData = [
            'Company Name' => 'Acme Corp',
        ];

        $this->assertSame(
            'Acme Corp',
            FormFieldAliasResolver::resolveCompany(
                $rowData,
                FormFieldAliasResolver::FORM_AFS,
            ),
        );
    }
}
