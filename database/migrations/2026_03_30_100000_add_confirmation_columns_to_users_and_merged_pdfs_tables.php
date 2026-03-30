<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $userColumns = [
        'confirmation_template_file_name',
        'confirmation_template_storage_path',
        'confirmation_template_file_size',
    ];

    /**
     * @var list<string>
     */
    private array $mergedPdfColumns = [
        'confirmation_file_name',
        'confirmation_storage_path',
        'confirmation_file_size',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $missingUserColumns = array_values(array_filter(
            $this->userColumns,
            fn (string $column): bool => ! Schema::hasColumn('users', $column),
        ));

        if ($missingUserColumns !== []) {
            Schema::table('users', function (Blueprint $table) use ($missingUserColumns) {
                if (in_array('confirmation_template_file_name', $missingUserColumns, true)) {
                    $table->string('confirmation_template_file_name')->nullable();
                }

                if (in_array('confirmation_template_storage_path', $missingUserColumns, true)) {
                    $table->string('confirmation_template_storage_path')->nullable();
                }

                if (in_array('confirmation_template_file_size', $missingUserColumns, true)) {
                    $table->unsignedBigInteger('confirmation_template_file_size')->nullable();
                }
            });
        }

        $missingMergedPdfColumns = array_values(array_filter(
            $this->mergedPdfColumns,
            fn (string $column): bool => ! Schema::hasColumn('merged_pdfs', $column),
        ));

        if ($missingMergedPdfColumns !== []) {
            Schema::table('merged_pdfs', function (Blueprint $table) use ($missingMergedPdfColumns) {
                if (in_array('confirmation_file_name', $missingMergedPdfColumns, true)) {
                    $table->string('confirmation_file_name')->nullable();
                }

                if (in_array('confirmation_storage_path', $missingMergedPdfColumns, true)) {
                    $table->string('confirmation_storage_path')->nullable();
                }

                if (in_array('confirmation_file_size', $missingMergedPdfColumns, true)) {
                    $table->unsignedBigInteger('confirmation_file_size')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $existingMergedPdfColumns = array_values(array_filter(
            $this->mergedPdfColumns,
            fn (string $column): bool => Schema::hasColumn('merged_pdfs', $column),
        ));

        if ($existingMergedPdfColumns !== []) {
            Schema::table('merged_pdfs', function (Blueprint $table) use ($existingMergedPdfColumns) {
                $table->dropColumn($existingMergedPdfColumns);
            });
        }

        $existingUserColumns = array_values(array_filter(
            $this->userColumns,
            fn (string $column): bool => Schema::hasColumn('users', $column),
        ));

        if ($existingUserColumns !== []) {
            Schema::table('users', function (Blueprint $table) use ($existingUserColumns) {
                $table->dropColumn($existingUserColumns);
            });
        }
    }
};
