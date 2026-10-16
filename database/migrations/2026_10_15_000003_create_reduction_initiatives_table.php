<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rencana aksi reduksi emisi per project (mis. ganti lampu LED, carpool)
        Schema::create('reduction_initiatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('emission_categories')->nullOnDelete();
            // Estimasi penghematan per tahun
            $table->decimal('estimated_reduction_kg', 15, 4);
            $table->enum('status', ['planned', 'in_progress', 'done', 'cancelled'])->default('planned');
            $table->foreignId('pic_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reduction_initiatives');
    }
};
