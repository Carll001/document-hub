<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::updateOrCreate([
            'name' => 'Super Admin',
            'email' => 'superadmin@gmail.com',
            'role' => UserRole::Superadmin,
            'password' => Hash::make('password'),
        ]);
    }
}
