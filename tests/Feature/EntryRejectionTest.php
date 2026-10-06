<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class EntryRejectionTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    private CarbonEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->travelTo('2026-10-06 10:00:00');
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member, 'member');
        $this->entry = $this->makeEntry($this->project, $this->member, 'submitted');
    }

    private function reject(User $as, ?CarbonEntry $entry = null, array $payload = ['reason' => 'Faktor emisi salah, pakai solar.'])
    {
        $entry ??= $this->entry;

        return $this->actingAs($as, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/entries/{$entry->id}/reject", $payload);
    }

    public function test_owner_rejects_submitted_entry_with_reason(): void
    {
        $this->reject($this->owner)
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Faktor emisi salah, pakai solar.')
            ->assertJsonPath('data.rejected_by.id', $this->owner->id);

        $this->assertNotNull($this->entry->fresh()->rejected_at);
    }

    public function test_admin_can_reject_entry_of_others(): void
    {
        $this->reject($this->admin)->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_reason_is_required(): void
    {
        $this->reject($this->owner, payload: [])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_only_submitted_entries_can_be_rejected(): void
    {
        $draft = $this->makeEntry($this->project, $this->member, 'draft');

        $this->reject($this->owner, $draft)->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_cannot_reject_own_entry(): void
    {
        $own = $this->makeEntry($this->project, $this->owner, 'submitted');

        $this->reject($this->owner, $own)->assertForbidden();
    }

    public function test_regular_member_cannot_reject(): void
    {
        $other = User::factory()->create();
        $this->addMember($this->project, $other, 'member');

        $this->reject($other)->assertForbidden();
        $this->assertSame('submitted', $this->entry->fresh()->status);
    }

    public function test_rejected_entry_can_be_edited_and_resubmitted(): void
    {
        $this->reject($this->owner)->assertOk();
        $base = "/api/v1/projects/{$this->project->id}/entries/{$this->entry->id}";

        $this->actingAs($this->member, 'sanctum')->putJson($base, [
            'emission_factor_id' => $this->gasolineFactor()->id,
            'quantity' => 42,
            'entry_date' => '2026-03-15',
        ])->assertOk()->assertJsonPath('data.quantity', 42);

        $this->actingAs($this->member, 'sanctum')->postJson("{$base}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.rejection_reason', null);
    }

    public function test_reviewer_who_last_edited_the_entry_cannot_approve_or_reject_it(): void
    {
        $this->reject($this->owner)->assertOk();
        $base = "/api/v1/projects/{$this->project->id}/entries/{$this->entry->id}";

        // Owner mengganti angka milik anggota lalu men-submit-nya sendiri
        $this->actingAs($this->owner, 'sanctum')->putJson($base, [
            'emission_factor_id' => $this->gasolineFactor()->id,
            'quantity' => 1,
            'entry_date' => '2026-03-15',
        ])->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("{$base}/submit")->assertOk();

        $this->actingAs($this->owner, 'sanctum')->postJson("{$base}/approve")->assertForbidden();
        $this->reject($this->owner)->assertForbidden();
        $this->assertSame('submitted', $this->entry->fresh()->status);

        // Reviewer lain (admin) tetap bisa
        $this->actingAs($this->admin, 'sanctum')->postJson("{$base}/approve")->assertOk();
    }

    public function test_owner_can_approve_after_member_fixes_rejected_entry(): void
    {
        $this->reject($this->owner)->assertOk();
        $base = "/api/v1/projects/{$this->project->id}/entries/{$this->entry->id}";

        $this->actingAs($this->member, 'sanctum')->putJson($base, [
            'emission_factor_id' => $this->gasolineFactor()->id,
            'quantity' => 50,
            'entry_date' => '2026-03-15',
        ])->assertOk();
        $this->actingAs($this->member, 'sanctum')->postJson("{$base}/submit")->assertOk();

        $this->actingAs($this->owner, 'sanctum')->postJson("{$base}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_rejected_entries_are_not_counted_in_target_progress(): void
    {
        $this->reject($this->owner)->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/targets", [
            'period_type' => 'yearly', 'period_year' => 2026, 'target_co2e_kg' => 1000,
        ])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/targets/progress")
            ->assertJsonPath('data.0.actual_co2e_kg', 0);
    }
}
