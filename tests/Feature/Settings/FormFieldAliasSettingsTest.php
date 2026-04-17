<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\FormFieldAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FormFieldAliasSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_view_alias_settings_page(): void
    {
        $this->withoutVite();
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($superadmin)
            ->get(route('aliases.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Aliases')
                ->has('registry.global.tin')
                ->has('registry.perForm.afs.tin')
                ->has('registry.perForm.1702ex.tin')
                ->has('registry.perForm.2551q.tin'));
    }

    public function test_non_superadmin_cannot_access_alias_settings_page(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)
            ->get(route('aliases.edit'))
            ->assertForbidden();
    }

    public function test_superadmin_can_update_alias_settings(): void
    {
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        $payload = [
            'entries' => [
                [
                    'form_type' => 'global',
                    'canonical_key' => 'tin',
                    'aliases' => ['tin', 'tax id number'],
                ],
                [
                    'form_type' => 'afs',
                    'canonical_key' => 'tin',
                    'aliases' => ['company tin'],
                ],
                [
                    'form_type' => '1702ex',
                    'canonical_key' => 'tin',
                    'aliases' => ['tin'],
                ],
                [
                    'form_type' => '2551q',
                    'canonical_key' => 'tin',
                    'aliases' => ['vat tin'],
                ],
                [
                    'form_type' => 'afs',
                    'canonical_key' => 'company_name',
                    'aliases' => ['company name'],
                ],
            ],
        ];

        $this->actingAs($superadmin)
            ->put(route('aliases.update'), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('form_field_aliases', [
            'form_type' => 'global',
            'canonical_key' => 'tin',
        ]);

        $this->assertSame(
            ['vat tin'],
            FormFieldAlias::query()
                ->where('form_type', '2551q')
                ->where('canonical_key', 'tin')
                ->firstOrFail()
                ->aliases_json,
        );
    }
}
