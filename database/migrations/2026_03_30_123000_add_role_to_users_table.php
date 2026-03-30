<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role', 20)
                    ->default(UserRole::Staff->value)
                    ->after('email');
            });
        }

        DB::table('users')
            ->whereNull('role')
            ->update(['role' => UserRole::Staff->value]);

        if (! Schema::hasIndex('users', 'users_role_index')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index('role');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasIndex('users', 'users_role_index')) {
                $table->dropIndex('users_role_index');
            }

            $table->dropColumn('role');
        });
    }
};
