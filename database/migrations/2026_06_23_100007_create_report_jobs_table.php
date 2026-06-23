<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->json('filters');
            $table->string('format', 10)->default('xlsx');
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_jobs');
    }
};
