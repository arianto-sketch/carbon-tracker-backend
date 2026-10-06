<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class ProjectAccessRulesTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->travelTo('2026-10-06 10:00:00');
        $this->owner = User::factory()->create();
        $this->project = $this->makeProject($this->owner);
    }

    private function entryPayload(): array
    {
        return ['emission_factor_id' => $this->gasolineFactor()->id, 'quantity' => 10, 'entry_date' => '2026-03-15'];
    }

    private function assertCannotWrite(User $user): void
    {
        $draft = $this->makeEntry($this->project, $this->owner);
        $base = "/api/v1/projects/{$this->project->id}/entries";

        $this->actingAs($user, 'sanctum')->postJson($base, $this->entryPayload())->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson("{$base}/bulk", ['entries' => [$this->entryPayload()]])->assertForbidden();
        $this->actingAs($user, 'sanctum')->putJson("{$base}/{$draft->id}", $this->entryPayload())->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson("{$base}/{$draft->id}/submit")->assertForbidden();
        $this->actingAs($user, 'sanctum')->deleteJson("{$base}/{$draft->id}")->assertForbidden();
    }

    public function test_project_viewer_cannot_write_entries(): void
    {
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, 'viewer');

        $this->assertCannotWrite($viewer);
    }

    public function test_global_viewer_cannot_write_entries_even_as_project_member(): void
    {
        $viewer = User::factory()->viewer()->create();
        $this->addMember($this->project, $viewer, 'member');

        $this->assertCannotWrite($viewer);
    }

    public function test_viewer_can_still_read_entries(): void
    {
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/entries")
            ->assertOk();
    }

    public function test_project_member_can_create_entry(): void
    {
        $member = User::factory()->create();
        $this->addMember($this->project, $member, 'member');

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/entries", $this->entryPayload())
            ->assertCreated();
    }

    public function test_owner_can_approve_entry_of_another_member(): void
    {
        $member = User::factory()->create();
        $this->addMember($this->project, $member, 'member');
        $entry = $this->makeEntry($this->project, $member, 'submitted');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/entries/{$entry->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_owner_cannot_approve_own_entry(): void
    {
        $entry = $this->makeEntry($this->project, $this->owner, 'submitted');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/entries/{$entry->id}/approve")
            ->assertForbidden();

        $this->assertSame('submitted', $entry->fresh()->status);
    }

    public function test_admin_cannot_approve_own_entry(): void
    {
        $entry = $this->makeEntry($this->project, $this->admin, 'submitted');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/entries/{$entry->id}/approve")
            ->assertForbidden();
    }

    public function test_project_response_exposes_current_user_role(): void
    {
        $member = User::factory()->create();
        $this->addMember($this->project, $member, 'member');

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}")
            ->assertJsonPath('data.current_user_role', 'member');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}")
            ->assertJsonPath('data.current_user_role', null);
    }

    public function test_changing_password_revokes_other_tokens_but_keeps_current(): void
    {
        $current = $this->owner->createToken('laptop')->plainTextToken;
        $other = $this->owner->createToken('phone')->plainTextToken;

        $this->withToken($current)->putJson('/api/v1/auth/me/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/v1/auth/me')->assertOk();
    }
}
