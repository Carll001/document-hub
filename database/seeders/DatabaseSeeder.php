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
            'email' => 'bosxsuperadmin@analytica.ph',
            'role' => UserRole::Superadmin,
            'password' => Hash::make('feshword'),
        ]);
        User::updateOrCreate([
            'name' => 'Sample User',
            'email' => 'bosxuser1@gmail.com',
            'role' => UserRole::Staff,
            'password' => Hash::make('feshword'),
        ]);
        User::updateOrCreate([
            'name' => 'Sample User 2',
            'email' => 'bosxuser2@gmail.com',
            'role' => UserRole::Staff,
            'password' => Hash::make('password'),
        ]);
    }
}