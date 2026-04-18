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
        Schema::table('document_generator_signatures', function (Blueprint $table): void {
            $table->string('page2_placement_mode', 32)->default('fixed');
            $table->string('page2_anchor_text')->nullable();
            $table->string('page3_placement_mode', 32)->default('fixed');
            $table->string('page3_anchor_text')->nullable();
            $table->string('page4_placement_mode', 32)->default('fixed');
            $table->string('page4_anchor_text')->nullable();
            $table->string('page8_placement_mode', 32)->default('fixed');
            $table->string('page8_anchor_text')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_generator_signatures', function (Blueprint $table): void {
            $table->dropColumn([
                'page2_placement_mode',
                'page2_anchor_text',
                'page3_placement_mode',
                'page3_anchor_text',
                'page4_placement_mode',
                'page4_anchor_text',
                'page8_placement_mode',
                'page8_anchor_text',
            ]);
        });
    }
};
