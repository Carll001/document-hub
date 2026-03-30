<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'confirmation_file_name',
        'confirmation_storage_path',
        'confirmation_file_size',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $existingColumns = array_values(array_filter(
            $this->columns,
            fn (string $column): bool => Schema::hasColumn('merged_pdfs', $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table('merged_pdfs', function (Blueprint $table) use ($existingColumns) {
            $table->dropColumn($existingColumns);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $missingColumns = array_values(array_filter(
            $this->columns,
            fn (string $column): bool => ! Schema::hasColumn('merged_pdfs', $column),
        ));

        if ($missingColumns === []) {
            return;
        }

        Schema::table('merged_pdfs', function (Blueprint $table) use ($missingColumns) {
            if (in_array('confirmation_file_name', $missingColumns, true)) {
                $table->string('confirmation_file_name')->nullable();
            }

            if (in_array('confirmation_storage_path', $missingColumns, true)) {
                $table->string('confirmation_storage_path')->nullable();
            }

            if (in_array('confirmation_file_size', $missingColumns, true)) {
                $table->unsignedBigInteger('confirmation_file_size')->nullable();
            }
        });
    }
};
