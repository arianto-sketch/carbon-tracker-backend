<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Intensitas emisi = emisi disetujui ÷ jumlah anggota tim project (diisi owner), supaya project
 * besar dan kecil bisa dibandingkan.
 */
class EmissionIntensityTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['role' => 'pm']);
        $this->project = $this->makeProject($this->owner);
    }

    private function updateProject(array $overrides): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/projects/{$this->project->id}", array_merge([
            'name' => $this->project->name, 'code' => $this->project->code, 'start_date' => '2026-01-01',
        ], $overrides));
    }

    public function test_owner_sets_team_size_and_intensity_is_reported(): void
    {
        $this->updateProject(['team_size' => 4])->assertOk()->assertJsonPath('data.team_size', 4);
        $this->makeApprovedEntry($this->project, $this->owner, 100, '2026-03-15'); // 231 kg

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/projects?period_year=2026')
            ->assertOk()
            ->assertJsonPath('data.0.team_size', 4)
            ->assertJsonPath('data.0.intensity_kg_per_person', 57.75);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.team_size', 4)
            ->assertJsonPath('data.intensity_kg_per_person', 57.75);
    }

    public function test_intensity_is_null_without_team_size(): void
    {
        $this->makeApprovedEntry($this->project, $this->owner, 100, '2026-03-15');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/projects?period_year=2026')
            ->assertJsonPath('data.0.intensity_kg_per_person', null);
    }

    public function test_update_without_team_size_keeps_it_and_null_clears_it(): void
    {
        $this->updateProject(['team_size' => 4])->assertOk();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/projects/{$this->project->id}", [
            'name' => 'Nama baru', 'code' => $this->project->code, 'start_date' => '2026-01-01',
        ])->assertOk()->assertJsonPath('data.team_size', 4);

        $this->updateProject(['team_size' => null])->assertOk()->assertJsonPath('data.team_size', null);
    }

    public function test_team_size_is_validated(): void
    {
        $this->updateProject(['team_size' => 0])->assertUnprocessable()->assertJsonValidationErrors('team_size');
        $this->updateProject(['team_size' => 'banyak'])->assertUnprocessable()->assertJsonValidationErrors('team_size');
    }
}
