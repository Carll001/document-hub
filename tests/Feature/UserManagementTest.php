<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_users_can_view_the_users_page(): void
    {
        $this->withoutVite();

        $superadmin = User::factory()->create([
            'name' => 'Main Superadmin',
            'role' => UserRole::Superadmin,
        ]);
        $otherSuperadmin = User::factory()->create([
            'name' => 'Other Superadmin',
            'email' => 'other-superadmin@example.com',
            'role' => UserRole::Superadmin,
        ]);
        $staff = User::factory()->create([
            'name' => 'Staff Member',
            'email' => 'staff@example.com',
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($superadmin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users')
                ->where('storeUrl', route('users.store'))
                ->where('usersPageUrl', route('users.list'))
                ->has('users', 1)
                ->where('users.0.id', $staff->id)
                ->where('users.0.name', 'Staff Member')
                ->where('users.0.email', 'staff@example.com')
                ->where('users.0.role', UserRole::Staff->value)
                ->where('hasMoreUsers', false)
                ->where('nextUsersCursor', null),
            );

        $this->actingAs($otherSuperadmin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users', 1)
                ->where('users.0.id', $staff->id),
            );

        $this->assertDatabaseHas('users', [
            'id' => $otherSuperadmin->id,
            'role' => UserRole::Superadmin->value,
        ]);
    }

    public function test_superadmin_can_create_and_edit_staff_but_not_other_superadmins(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);
        $staff = User::factory()->create([
            'role' => UserRole::Staff,
        ]);
        $otherSuperadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);

        $this->actingAs($superadmin)
            ->post(route('users.store'), [
                'name' => 'New Staff',
                'email' => 'new-staff@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => UserRole::Superadmin->value,
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', 'Staff account created successfully.');

        $this->assertDatabaseHas('users', [
            'email' => 'new-staff@example.com',
            'role' => UserRole::Staff->value,
        ]);

        $this->actingAs($superadmin)
            ->put(route('users.update', ['user' => $staff]), [
                'name' => 'Updated Staff',
                'email' => $staff->email,
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', 'Updated Updated Staff.');

        $staff->refresh();

        $this->assertSame('Updated Staff', $staff->name);
        $this->assertTrue(Hash::check('newpassword123', $staff->password));

        $this->actingAs($superadmin)
            ->put(route('users.update', ['user' => $otherSuperadmin]), [
                'name' => 'Should Fail',
                'email' => $otherSuperadmin->email,
            ])
            ->assertNotFound();
    }

    public function test_superadmin_can_delete_staff_but_not_other_superadmins(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);
        $staff = User::factory()->create([
            'name' => 'Delete Me',
            'role' => UserRole::Staff,
        ]);
        $otherSuperadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('users.destroy', ['user' => $staff]))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', 'Deleted Delete Me.');

        $this->assertDatabaseMissing('users', [
            'id' => $staff->id,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('users.destroy', ['user' => $otherSuperadmin]))
            ->assertNotFound();

        $this->assertDatabaseHas('users', [
            'id' => $otherSuperadmin->id,
            'role' => UserRole::Superadmin->value,
        ]);
    }

    public function test_superadmin_can_bulk_delete_staff_but_not_superadmins(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);
        $firstStaff = User::factory()->create([
            'role' => UserRole::Staff,
        ]);
        $secondStaff = User::factory()->create([
            'role' => UserRole::Staff,
        ]);
        $otherSuperadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('users.destroy-many'), [
                'user_ids' => [$firstStaff->id, $secondStaff->id],
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', 'Deleted 2 users.');

        $this->assertDatabaseMissing('users', [
            'id' => $firstStaff->id,
        ]);
        $this->assertDatabaseMissing('users', [
            'id' => $secondStaff->id,
        ]);

        $protectedStaff = User::factory()->create([
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('users.destroy-many'), [
                'user_ids' => [$protectedStaff->id, $otherSuperadmin->id],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('users', [
            'id' => $protectedStaff->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $otherSuperadmin->id,
            'role' => UserRole::Superadmin->value,
        ]);
    }

    public function test_superadmin_creation_requires_confirmed_password(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);

        $this->actingAs($superadmin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Managed Staff',
                'email' => 'managed-staff@example.com',
                'password' => 'password123',
                'password_confirmation' => 'different-password',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHasErrors('password');
    }

    public function test_staff_cannot_access_users_page(): void
    {
        $staff = User::factory()->create([
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($staff)
            ->get(route('users.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_is_redirected_away_from_staff_tools(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);

        $this->actingAs($superadmin)
            ->get(route('doc-merge.index'))
            ->assertRedirect(route('users.index'));

        $this->actingAs($superadmin)
            ->get(route('email-sync.index'))
            ->assertRedirect(route('users.index'));
    }
}
