<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Scope faktor bawaan seeder; faktor lain (buatan admin) dianggap Scope 3 sampai diubah admin */
    private const SEEDED_SCOPES = [
        1 => ['gasoline_vehicle', 'diesel_vehicle'],
        2 => ['electricity_jawa_bali', 'electricity_sumatra'],
    ];

    public function up(): void
    {
        Schema::table('emission_factors', function (Blueprint $table) {
            // Scope GHG Protocol: 1 = emisi langsung, 2 = listrik/energi yang dibeli, 3 = tidak langsung lainnya
            $table->unsignedTinyInteger('scope')->default(3)->after('category_id');
        });

        Schema::table('carbon_entries', function (Blueprint $table) {
            // Disalin dari faktor saat entri dicatat, sama seperti emission_factor_value
            $table->unsignedTinyInteger('scope')->default(3)->after('category_id');
        });

        foreach (self::SEEDED_SCOPES as $scope => $slugs) {
            DB::table('emission_factors')->whereIn('slug', $slugs)->update(['scope' => $scope]);
        }

        // Entri yang sudah ada (termasuk yang soft-deleted) mengikuti scope faktornya saat ini
        DB::update('UPDATE carbon_entries SET scope = (SELECT scope FROM emission_factors WHERE emission_factors.id = carbon_entries.emission_factor_id)');
    }

    public function down(): void
    {
        Schema::table('carbon_entries', function (Blueprint $table) {
            $table->dropColumn('scope');
        });

        Schema::table('emission_factors', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
