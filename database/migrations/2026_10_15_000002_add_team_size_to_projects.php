<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Pembagi intensitas emisi (kg CO2e per orang); diisi owner, kosong = intensitas tidak dihitung
            $table->unsignedInteger('team_size')->nullable()->after('client_name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('team_size');
        });
    }
};
