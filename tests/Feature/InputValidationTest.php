<?php

namespace Tests\Feature;

use App\Models\CarbonTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class InputValidationTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->pm = User::factory()->create();
    }

    public function test_dashboard_rejects_invalid_period_and_limit_with_422(): void
    {
        foreach ([
            '/api/v1/dashboard/summary?period_month=13',
            '/api/v1/dashboard/summary?period_month=abc',
            '/api/v1/dashboard/category-breakdown?period_year=1999',
            '/api/v1/dashboard/top-entries?limit=-1',
            '/api/v1/dashboard/top-entries?limit=1000',
        ] as $url) {
            $this->actingAs($this->pm, 'sanctum')->getJson($url)->assertStatus(422);
        }

        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/dashboard/summary?period_month=3&period_year=2026')->assertOk();
    }

    private function postTarget(int $projectId, array $data)
    {
        return $this->actingAs($this->pm, 'sanctum')->postJson(
            "/api/v1/projects/{$projectId}/targets",
            $data + ['period_year' => 2026, 'target_co2e_kg' => 1000],
        );
    }

    public function test_target_period_value_must_match_period_type(): void
    {
        $project = $this->makeProject($this->pm);

        $this->postTarget($project->id, ['period_type' => 'monthly'])
            ->assertStatus(422)->assertJsonValidationErrors('period_value');
        $this->postTarget($project->id, ['period_type' => 'quarterly', 'period_value' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('period_value');

        $this->postTarget($project->id, ['period_type' => 'monthly', 'period_value' => 12])->assertCreated();
        $this->postTarget($project->id, ['period_type' => 'quarterly', 'period_value' => 4])->assertCreated();

        $yearlyId = $this->postTarget($project->id, ['period_type' => 'yearly', 'period_value' => 3])
            ->assertCreated()->json('data.id');
        $this->assertNull(CarbonTarget::find($yearlyId)->period_value);
    }

    public function test_duplicate_target_for_same_period_is_rejected_with_422(): void
    {
        $project = $this->makeProject($this->pm);

        $this->postTarget($project->id, ['period_type' => 'yearly'])->assertCreated();
        $this->postTarget($project->id, ['period_type' => 'yearly'])
            ->assertStatus(422)->assertJsonValidationErrors('period_type');
    }

    public function test_deleted_target_can_be_recreated(): void
    {
        $project = $this->makeProject($this->pm);
        $data = ['period_type' => 'monthly', 'period_value' => 3, 'category_id' => $this->gasolineFactor()->category_id];

        $id = $this->postTarget($project->id, $data)->assertCreated()->json('data.id');
        $this->actingAs($this->pm, 'sanctum')->deleteJson("/api/v1/projects/{$project->id}/targets/{$id}")->assertOk();

        $this->postTarget($project->id, $data)->assertCreated();
    }

    private function postEntry(int $projectId, string $date)
    {
        return $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/projects/{$projectId}/entries", [
            'emission_factor_id' => $this->gasolineFactor()->id,
            'quantity' => 10,
            'entry_date' => $date,
        ]);
    }

    public function test_entry_date_cannot_be_in_the_future_in_business_timezone(): void
    {
        $project = $this->makeProject($this->pm);

        // 10:00 UTC = 17:00 WIB, tanggal 6 Okt di Jakarta -> 7 Okt adalah masa depan
        $this->travelTo('2026-10-06 10:00:00');
        $this->postEntry($project->id, '2026-10-06')->assertCreated();
        $this->postEntry($project->id, '2026-10-07')->assertStatus(422)->assertJsonValidationErrors('entry_date');

        // 20:00 UTC = 03:00 WIB tanggal 7 Okt -> "hari ini" user WIB harus diterima
        $this->travelTo('2026-10-06 20:00:00');
        $this->postEntry($project->id, '2026-10-07')->assertCreated();
        $this->postEntry($project->id, '2026-10-08')->assertStatus(422);
    }
}
