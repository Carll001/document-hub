<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filing_outputs', function (Blueprint $table): void {
            $table->string('president_signature_path')->nullable()->after('file_path');
        });

        DB::table('afs_filing_items')
            ->where('status', 'pdf_done')
            ->whereNull('signature_applied_at')
            ->update(['status' => 'generated']);

        DB::table('afs_filing_items')
            ->where('status', 'pdf_done')
            ->whereNotNull('signature_applied_at')
            ->update(['status' => 'signed']);

        DB::table('filing_outputs')
            ->where('form_type', 'afs')
            ->where('status', 'pdf_done')
            ->update(['status' => 'generated']);
    }

    public function down(): void
    {
        DB::table('afs_filing_items')
            ->where('status', 'signed')
            ->update(['status' => 'pdf_done']);

        DB::table('afs_filing_items')
            ->where('status', 'generated')
            ->update(['status' => 'pdf_done']);

        DB::table('filing_outputs')
            ->where('form_type', 'afs')
            ->whereIn('status', ['generated', 'signed'])
            ->update(['status' => 'pdf_done']);

        Schema::table('filing_outputs', function (Blueprint $table): void {
            $table->dropColumn('president_signature_path');
        });
    }
};
