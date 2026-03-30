<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        DB::table('users')
            ->where('role', 'admin')
            ->update(['role' => UserRole::Superadmin->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        DB::table('users')
            ->where('role', UserRole::Superadmin->value)
            ->update(['role' => 'admin']);
    }
};
