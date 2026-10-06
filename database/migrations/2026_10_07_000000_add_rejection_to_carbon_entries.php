<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // change() wajib menyebut ulang default agar tidak hilang
        Schema::table('carbon_entries', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft')->change();
        });

        Schema::table('carbon_entries', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('approved_at');
            $table->foreignId('rejected_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        // Entri yang ditolak dikembalikan ke draft sebelum nilai enum 'rejected' dihapus
        DB::table('carbon_entries')->where('status', 'rejected')->update(['status' => 'draft']);

        Schema::table('carbon_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejection_reason', 'rejected_at']);
        });

        Schema::table('carbon_entries', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'approved'])->default('draft')->change();
        });
    }
};
