<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('form_field_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('form_type', 32);
            $table->string('canonical_key', 64);
            $table->json('aliases_json');
            $table->timestamps();

            $table->unique(['form_type', 'canonical_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_field_aliases');
    }
};
