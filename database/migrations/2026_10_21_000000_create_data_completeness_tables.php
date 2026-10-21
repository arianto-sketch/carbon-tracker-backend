<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Tidak ada data bulan ini": faktor rutin (atau seluruh project) yang memang kosong di bulan itu
        // tidak diingatkan lagi. Satu baris per project + faktor + bulan, dijaga di aplikasi karena
        // unique index tidak berlaku untuk emission_factor_id NULL.
        Schema::create('data_gap_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // NULL = seluruh project (tidak ada entri sama sekali bulan itu)
            $table->foreignId('emission_factor_id')->nullable()->constrained('emission_factors')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'period_year', 'period_month']);
        });

        // Putaran pengingat yang sudah dikirim (1 = tanggal 5, 2 = tanggal 10): run ulang tidak mengirim dobel
        Schema::create('data_reminder_runs', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7);
            $table->unsignedTinyInteger('round');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('ran_at');

            $table->unique(['period', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_reminder_runs');
        Schema::dropIfExists('data_gap_dismissals');
    }
};
