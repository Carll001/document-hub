<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_generator_signatures', function (Blueprint $table): void {
            $table->string('page2_anchor', 32)->default('bottom_right');
            $table->decimal('page2_offset_x', 8, 2)->default(0);
            $table->decimal('page2_offset_y', 8, 2)->default(0);
            $table->decimal('page2_width', 8, 2)->default(40);
            $table->decimal('page2_height', 8, 2)->default(16);

            $table->string('page3_anchor', 32)->default('bottom_right');
            $table->decimal('page3_offset_x', 8, 2)->default(0);
            $table->decimal('page3_offset_y', 8, 2)->default(0);
            $table->decimal('page3_width', 8, 2)->default(40);
            $table->decimal('page3_height', 8, 2)->default(16);
        });
    }

    public function down(): void
    {
        Schema::table('document_generator_signatures', function (Blueprint $table): void {
            $table->dropColumn([
                'page2_anchor',
                'page2_offset_x',
                'page2_offset_y',
                'page2_width',
                'page2_height',
                'page3_anchor',
                'page3_offset_x',
                'page3_offset_y',
                'page3_width',
                'page3_height',
            ]);
        });
    }
};

