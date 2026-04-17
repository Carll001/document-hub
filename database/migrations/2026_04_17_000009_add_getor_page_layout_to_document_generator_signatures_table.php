<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_generator_signatures', function (Blueprint $table): void {
            $table->string('page4_anchor', 32)->default('bottom_right');
            $table->decimal('page4_offset_x', 8, 2)->default(0);
            $table->decimal('page4_offset_y', 8, 2)->default(0);
            $table->decimal('page4_width', 8, 2)->default(40);
            $table->decimal('page4_height', 8, 2)->default(16);

            $table->string('page8_anchor', 32)->default('bottom_right');
            $table->decimal('page8_offset_x', 8, 2)->default(0);
            $table->decimal('page8_offset_y', 8, 2)->default(0);
            $table->decimal('page8_width', 8, 2)->default(40);
            $table->decimal('page8_height', 8, 2)->default(16);
        });
    }

    public function down(): void
    {
        Schema::table('document_generator_signatures', function (Blueprint $table): void {
            $table->dropColumn([
                'page4_anchor',
                'page4_offset_x',
                'page4_offset_y',
                'page4_width',
                'page4_height',
                'page8_anchor',
                'page8_offset_x',
                'page8_offset_y',
                'page8_width',
                'page8_height',
            ]);
        });
    }
};

