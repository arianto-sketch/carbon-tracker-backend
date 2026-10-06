<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique index lama ikut menghitung baris soft-deleted (target yang dihapus tidak bisa
 * dibuat ulang -> 500) dan tidak mencegah duplikat saat category_id/period_value NULL.
 * Keunikan kini dijaga di CarbonTargetService::ensureUniquePeriod().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carbon_targets', function (Blueprint $table) {
            // Pastikan FK project_id tetap punya index sendiri sebelum index komposit dihapus (MySQL)
            $table->index('project_id', 'carbon_targets_project_id_index');
            $table->dropUnique('carbon_targets_unique');
        });
    }

    public function down(): void
    {
        Schema::table('carbon_targets', function (Blueprint $table) {
            $table->unique(['project_id', 'category_id', 'period_type', 'period_year', 'period_value'], 'carbon_targets_unique');
            $table->dropIndex('carbon_targets_project_id_index');
        });
    }
};
