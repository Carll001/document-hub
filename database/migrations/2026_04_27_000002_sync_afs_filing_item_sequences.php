<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->syncPrimaryKeySequence('afs_filing_items');
        $this->syncPrimaryKeySequence('afs_filing_item_activity_logs');
    }

    public function down(): void
    {
        // no-op
    }

    private function syncPrimaryKeySequence(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), true)"
            );

            return;
        }

        if ($driver === 'mysql') {
            $nextId = ((int) DB::table($table)->max('id')) + 1;
            DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = {$nextId}");
        }
    }
};
