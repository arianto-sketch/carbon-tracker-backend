<?php

namespace Tests\Concerns;

use App\Models\CarbonEntry;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Database\Seeders\EmissionCategorySeeder;
use Database\Seeders\EmissionFactorSeeder;

trait BuildsCarbonData
{
    protected User $admin;

    protected function seedMasterData(): void
    {
        $this->admin = User::factory()->admin()->create();
        $this->seed([EmissionCategorySeeder::class, EmissionFactorSeeder::class]);
    }

    protected function gasolineFactor(): EmissionFactor
    {
        return EmissionFactor::where('slug', 'gasoline_vehicle')->firstOrFail();
    }

    protected function makeProject(User $owner, string $code = 'PRJ-1'): Project
    {
        $project = Project::create([
            'name' => "Project {$code}",
            'code' => $code,
            'start_date' => '2026-01-01',
            'created_by' => $owner->id,
        ]);

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $project;
    }

    protected function makeApprovedEntry(Project $project, User $creator, float $quantity = 100, string $date = '2026-03-15'): CarbonEntry
    {
        $factor = $this->gasolineFactor();

        return CarbonEntry::create([
            'project_id' => $project->id,
            'emission_factor_id' => $factor->id,
            'category_id' => $factor->category_id,
            'entry_date' => $date,
            'period_month' => (int) substr($date, 5, 2),
            'period_year' => (int) substr($date, 0, 4),
            'quantity' => $quantity,
            'source_unit' => $factor->source_unit,
            'emission_factor_value' => $factor->factor_value,
            'co2e_kg' => round($quantity * (float) $factor->factor_value, 4),
            'status' => 'approved',
            'approved_by' => $creator->id,
            'approved_at' => now(),
            'created_by' => $creator->id,
        ]);
    }
}
