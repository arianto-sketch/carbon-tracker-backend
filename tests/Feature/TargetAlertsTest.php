<?php

namespace Tests\Feature;

use App\Models\CarbonTarget;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class TargetAlertsTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->travelTo('2026-10-06 10:00:00');
        $this->pm = User::factory()->create();
    }

    /** Project dengan satu entri approved dan target tahunan sehingga pemakaian = $percent %. */
    private function projectAtUsage(User $owner, string $code, float $percent, int $year = 2026): Project
    {
        $project = $this->makeProject($owner, $code);
        $actual = (float) $this->makeApprovedEntry($project, $owner, 100, "{$year}-03-15")->co2e_kg;

        CarbonTarget::create([
            'project_id' => $project->id,
            'period_type' => 'yearly',
            'period_year' => $year,
            'target_co2e_kg' => round($actual / ($percent / 100), 4),
            'created_by' => $owner->id,
        ]);

        return $project;
    }

    public function test_alerts_include_targets_at_or_above_80_percent_sorted_by_usage(): void
    {
        $this->projectAtUsage($this->pm, 'P79', 79);
        $warning = $this->projectAtUsage($this->pm, 'P80', 80);
        $exceeded = $this->projectAtUsage($this->pm, 'P120', 120);

        $alerts = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/dashboard/target-alerts')
            ->assertOk()
            ->json('data');

        $this->assertSame([$exceeded->id, $warning->id], array_column($alerts, 'project_id'));
        $this->assertSame(['exceeded', 'warning'], array_column($alerts, 'level'));
        $this->assertSame('Project P120', $alerts[0]['project_name']);
        $this->assertEqualsWithDelta(80, $alerts[1]['percentage_used'], 0.01);
    }

    public function test_alerts_are_scoped_to_users_projects_and_current_year(): void
    {
        $this->projectAtUsage($this->pm, 'MINE', 90);
        $this->projectAtUsage($this->pm, 'OLD', 150, 2025);
        $this->projectAtUsage(User::factory()->create(), 'OTHER', 95);

        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/dashboard/target-alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project_name', 'Project MINE');
    }

    public function test_admin_sees_alerts_of_all_projects(): void
    {
        $this->projectAtUsage($this->pm, 'MINE', 90);
        $this->projectAtUsage(User::factory()->create(), 'OTHER', 95);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/dashboard/target-alerts')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_no_alerts_returns_empty_list(): void
    {
        $this->projectAtUsage($this->pm, 'SAFE', 50);

        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/dashboard/target-alerts')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
