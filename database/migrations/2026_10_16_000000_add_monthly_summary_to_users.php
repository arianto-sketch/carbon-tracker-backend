<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Email ringkasan bulanan (owner project & admin); bisa dimatikan di Profil
            $table->boolean('monthly_summary_email')->default(true)->after('email_notifications');
            // Periode (YYYY-MM) ringkasan terakhir yang dikirim: run ulang di bulan yang sama tidak mengirim dobel
            $table->string('last_monthly_summary_period', 7)->nullable()->after('monthly_summary_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['monthly_summary_email', 'last_monthly_summary_period']);
        });
    }
};
