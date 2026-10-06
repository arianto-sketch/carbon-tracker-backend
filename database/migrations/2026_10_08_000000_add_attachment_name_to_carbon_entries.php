<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carbon_entries', function (Blueprint $table) {
            // Nama file asli untuk ditampilkan/diunduh; attachment_path menyimpan nama acak di disk privat
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('carbon_entries', function (Blueprint $table) {
            $table->dropColumn('attachment_name');
        });
    }
};
