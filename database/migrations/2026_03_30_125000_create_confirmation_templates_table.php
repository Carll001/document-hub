<?php

use App\Models\ConfirmationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $legacyUserColumns = [
        'confirmation_template_file_name',
        'confirmation_template_storage_path',
        'confirmation_template_file_size',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('confirmation_templates')) {
            Schema::create('confirmation_templates', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('file_name');
                $table->string('storage_path');
                $table->unsignedBigInteger('file_size')->nullable();
                $table->foreignId('uploaded_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();
            });
        }

        if (
            Schema::hasColumn('users', 'confirmation_template_storage_path')
            && Schema::hasColumn('users', 'confirmation_template_file_name')
        ) {
            $legacyTemplate = DB::table('users')
                ->whereNotNull('confirmation_template_storage_path')
                ->whereNotNull('confirmation_template_file_name')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first([
                    'id',
                    'confirmation_template_file_name',
                    'confirmation_template_storage_path',
                    'confirmation_template_file_size',
                ]);

            if ($legacyTemplate !== null) {
                DB::table('confirmation_templates')->updateOrInsert(
                    ['key' => ConfirmationTemplate::SHARED_KEY],
                    [
                        'file_name' => (string) $legacyTemplate->confirmation_template_file_name,
                        'storage_path' => (string) $legacyTemplate->confirmation_template_storage_path,
                        'file_size' => $legacyTemplate->confirmation_template_file_size,
                        'uploaded_by_user_id' => $legacyTemplate->id,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }

        $existingLegacyColumns = array_values(array_filter(
            $this->legacyUserColumns,
            fn (string $column): bool => Schema::hasColumn('users', $column),
        ));

        if ($existingLegacyColumns !== []) {
            Schema::table('users', function (Blueprint $table) use ($existingLegacyColumns) {
                $table->dropColumn($existingLegacyColumns);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $missingLegacyColumns = array_values(array_filter(
            $this->legacyUserColumns,
            fn (string $column): bool => ! Schema::hasColumn('users', $column),
        ));

        if ($missingLegacyColumns !== []) {
            Schema::table('users', function (Blueprint $table) use ($missingLegacyColumns) {
                if (in_array('confirmation_template_file_name', $missingLegacyColumns, true)) {
                    $table->string('confirmation_template_file_name')->nullable();
                }

                if (in_array('confirmation_template_storage_path', $missingLegacyColumns, true)) {
                    $table->string('confirmation_template_storage_path')->nullable();
                }

                if (in_array('confirmation_template_file_size', $missingLegacyColumns, true)) {
                    $table->unsignedBigInteger('confirmation_template_file_size')->nullable();
                }
            });
        }

        if (Schema::hasTable('confirmation_templates')) {
            $sharedTemplate = DB::table('confirmation_templates')
                ->where('key', ConfirmationTemplate::SHARED_KEY)
                ->first([
                    'file_name',
                    'storage_path',
                    'file_size',
                    'uploaded_by_user_id',
                ]);

            if (
                $sharedTemplate !== null
                && $sharedTemplate->uploaded_by_user_id !== null
            ) {
                DB::table('users')
                    ->where('id', $sharedTemplate->uploaded_by_user_id)
                    ->update([
                        'confirmation_template_file_name' => $sharedTemplate->file_name,
                        'confirmation_template_storage_path' => $sharedTemplate->storage_path,
                        'confirmation_template_file_size' => $sharedTemplate->file_size,
                        'updated_at' => now(),
                    ]);
            }

            Schema::drop('confirmation_templates');
        }
    }
};
