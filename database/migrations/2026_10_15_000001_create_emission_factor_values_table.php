<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nilai faktor per periode: berlaku sejak valid_from sampai periode berikutnya dimulai.
        // emission_factors.factor_value tetap ada sebagai nilai yang berlaku hari ini.
        Schema::create('emission_factor_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_factor_id')->constrained('emission_factors')->cascadeOnDelete();
            $table->date('valid_from');
            $table->decimal('factor_value', 15, 8);
            $table->string('source', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['emission_factor_id', 'valid_from']);
        });

        // Nilai yang ada sekarang menjadi periode pertama, berlaku untuk semua tanggal
        $now = now();
        DB::table('emission_factors')->orderBy('id')->get()->each(fn ($factor) => DB::table('emission_factor_values')->insert([
            'emission_factor_id' => $factor->id,
            'valid_from'         => '2000-01-01',
            'factor_value'       => $factor->factor_value,
            'source'             => $factor->source,
            'created_by'         => $factor->created_by,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('emission_factor_values');
    }
};
