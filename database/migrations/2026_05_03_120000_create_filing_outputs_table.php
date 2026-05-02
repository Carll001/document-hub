<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filing_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();

            $table->string('company_name');
            $table->string('tin', 64);
            $table->string('form_type', 32); // afs | 1702ex
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('status', 32)->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['form_type', 'created_at'], 'filing_outputs_form_created_idx');
            $table->index(['company_id', 'form_type'], 'filing_outputs_company_form_idx');
            $table->index(['status', 'updated_at'], 'filing_outputs_status_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filing_outputs');
    }
};
