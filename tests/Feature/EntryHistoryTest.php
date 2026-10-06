<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class EntryHistoryTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->travelTo('2026-10-06 10:00:00');
        $this->owner = User::factory()->create(['name' => 'Owner Satu']);
        $this->member = User::factory()->create(['name' => 'Member Dua']);
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member, 'member');
    }

    /** Jalankan alur lengkap lewat API: create -> update -> submit -> approve. Mengembalikan id entri. */
    private function runWorkflow(): int
    {
        $base = "/api/v1/projects/{$this->project->id}/entries";
        $payload = ['emission_factor_id' => $this->gasolineFactor()->id, 'quantity' => 10, 'entry_date' => '2026-03-15'];

        $id = $this->actingAs($this->member, 'sanctum')->postJson($base, $payload)->assertCreated()->json('data.id');
        $this->actingAs($this->member, 'sanctum')->putJson("{$base}/{$id}", ['quantity' => 42] + $payload)->assertOk();
        $this->actingAs($this->member, 'sanctum')->postJson("{$base}/{$id}/submit")->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("{$base}/{$id}/approve")->assertOk();

        return $id;
    }

    public function test_history_lists_workflow_events_in_order_with_actor(): void
    {
        $id = $this->runWorkflow();

        $history = $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/entries/{$id}/history")
            ->assertOk()
            ->json('data');

        $this->assertSame(['created', 'updated', 'submitted', 'approved'], array_column($history, 'event'));
        $this->assertSame(
            ['Member Dua', 'Member Dua', 'Member Dua', 'Owner Satu'],
            array_map(fn ($h) => $h['user']['name'] ?? null, $history),
        );
    }

    public function test_update_event_lists_only_changed_fields_without_timestamps(): void
    {
        $id = $this->runWorkflow();

        $update = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/entries/{$id}/history")
            ->assertOk()
            ->json('data.1');

        $this->assertArrayHasKey('quantity', $update['changes']);
        $this->assertEqualsWithDelta(10, (float) $update['changes']['quantity']['old'], 0.0001);
        $this->assertEqualsWithDelta(42, (float) $update['changes']['quantity']['new'], 0.0001);
        $this->assertArrayNotHasKey('updated_at', $update['changes']);
        $this->assertArrayNotHasKey('updated_by', $update['changes']);
    }

    public function test_non_member_cannot_read_history(): void
    {
        $id = $this->runWorkflow();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/entries/{$id}/history")
            ->assertForbidden();
    }

    public function test_entry_from_another_project_is_not_found(): void
    {
        $id = $this->runWorkflow();
        $other = $this->makeProject($this->owner, 'PRJ-2');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/entries/{$id}/history")
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$other->id}/entries/{$id}/history")
            ->assertNotFound();
    }
}
