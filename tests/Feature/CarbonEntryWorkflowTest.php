<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class CarbonEntryWorkflowTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->pm = User::factory()->create();
        $this->travelTo('2026-10-06 10:00:00');
    }

    private function createEntry(int $projectId, float $quantity = 100): array
    {
        return $this->actingAs($this->pm, 'sanctum')
            ->postJson("/api/v1/projects/{$projectId}/entries", [
                'emission_factor_id' => $this->gasolineFactor()->id,
                'quantity' => $quantity,
                'entry_date' => '2026-03-15',
            ])
            ->assertCreated()
            ->json('data');
    }

    public function test_co2e_is_quantity_times_snapshotted_factor(): void
    {
        $project = $this->makeProject($this->pm);
        $factor = $this->gasolineFactor();

        $entry = $this->createEntry($project->id, 100);

        $this->assertEqualsWithDelta(100 * (float) $factor->factor_value, (float) $entry['co2e_kg'], 0.0001);
        $this->assertSame('draft', $entry['status']);
    }

    public function test_submitted_entry_can_no_longer_be_edited(): void
    {
        $project = $this->makeProject($this->pm);
        $entry = $this->createEntry($project->id);

        $this->actingAs($this->pm, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/entries/{$entry['id']}/submit")
            ->assertOk();

        $this->actingAs($this->pm, 'sanctum')
            ->putJson("/api/v1/projects/{$project->id}/entries/{$entry['id']}", [
                'emission_factor_id' => $this->gasolineFactor()->id,
                'quantity' => 1,
                'entry_date' => '2026-03-15',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_entry_is_not_reachable_through_another_project_url(): void
    {
        $project = $this->makeProject($this->pm, 'PRJ-A');
        $other = $this->makeProject($this->pm, 'PRJ-B');
        $entry = $this->createEntry($project->id);

        $this->actingAs($this->pm, 'sanctum')
            ->getJson("/api/v1/projects/{$other->id}/entries/{$entry['id']}")
            ->assertNotFound();
    }

    public function test_target_progress_counts_only_approved_entries(): void
    {
        $project = $this->makeProject($this->pm);
        $this->makeApprovedEntry($project, $this->pm, 100);
        $this->createEntry($project->id, 500); // draft, must be ignored

        $this->actingAs($this->pm, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/targets", [
                'period_type' => 'yearly',
                'period_year' => 2026,
                'target_co2e_kg' => 1000,
            ])
            ->assertCreated();

        $progress = $this->actingAs($this->pm, 'sanctum')
            ->getJson("/api/v1/projects/{$project->id}/targets/progress")
            ->assertOk()
            ->json('data.0');

        $expected = 100 * (float) $this->gasolineFactor()->factor_value;
        $this->assertEqualsWithDelta($expected, $progress['actual_co2e_kg'], 0.0001);
        $this->assertEqualsWithDelta($expected / 10, $progress['percentage_used'], 0.01);
    }
}
