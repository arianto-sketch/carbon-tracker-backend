<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects');
            $table->foreignId('emission_factor_id')->constrained('emission_factors');
            $table->foreignId('category_id')->constrained('emission_categories');
            $table->date('entry_date');
            $table->tinyInteger('period_month')->unsigned();
            $table->smallInteger('period_year')->unsigned();
            $table->decimal('quantity', 15, 4);
            $table->string('source_unit', 50);
            $table->decimal('emission_factor_value', 15, 8);
            $table->decimal('co2e_kg', 15, 4);
            $table->text('description')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('activity_type')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'period_year', 'period_month']);
            $table->index(['project_id', 'category_id']);
            $table->index('entry_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_entries');
    }
};
