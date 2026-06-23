<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects');
            $table->foreignId('category_id')->nullable()->constrained('emission_categories');
            $table->enum('period_type', ['monthly', 'quarterly', 'yearly']);
            $table->smallInteger('period_year')->unsigned();
            $table->tinyInteger('period_value')->unsigned()->nullable();
            $table->decimal('target_co2e_kg', 15, 4);
            $table->decimal('baseline_co2e_kg', 15, 4)->nullable();
            $table->decimal('reduction_percentage', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['project_id', 'category_id', 'period_type', 'period_year', 'period_value'], 'carbon_targets_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_targets');
    }
};
