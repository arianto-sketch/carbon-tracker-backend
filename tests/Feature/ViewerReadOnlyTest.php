<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * User dengan role global Viewer hanya bisa melihat, apa pun role-nya di project.
 */
class ViewerReadOnlyTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $viewer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['role' => 'pm']);
        $this->viewer = User::factory()->create(['role' => 'viewer', 'name' => 'Vina Viewer']);
        $this->project = $this->makeProject($this->owner);
    }

    private function roleOf(User $user): ?string
    {
        return ProjectMember::where('project_id', $this->project->id)->where('user_id', $user->id)->value('role');
    }

    public function test_viewer_can_only_be_added_as_project_viewer(): void
    {
        $url = "/api/v1/projects/{$this->project->id}/members";

        foreach (['owner', 'member'] as $role) {
            $this->actingAs($this->owner, 'sanctum')->postJson($url, ['user_id' => $this->viewer->id, 'role' => $role])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('role');
        }

        $this->actingAs($this->owner, 'sanctum')->postJson($url, ['user_id' => $this->viewer->id, 'role' => 'viewer'])
            ->assertCreated();
    }

    public function test_viewer_cannot_be_promoted_within_a_project(): void
    {
        $this->addMember($this->project, $this->viewer, 'viewer');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$this->viewer->id}", ['role' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('viewer', $this->roleOf($this->viewer));
    }

    public function test_viewer_with_a_legacy_owner_membership_cannot_manage_the_project(): void
    {
        // Data lama: viewer sempat dijadikan owner sebelum aturan ini ada
        $this->addMember($this->project, $this->viewer, 'owner');
        $entry = $this->makeEntry($this->project, $this->owner, 'submitted');
        $as = $this->actingAs($this->viewer, 'sanctum');
        $base = "/api/v1/projects/{$this->project->id}";

        $as->putJson($base, ['name' => 'Diubah', 'code' => 'PRJ-1', 'start_date' => '2026-01-01'])->assertForbidden();
        $as->postJson("{$base}/members", ['user_id' => User::factory()->create()->id, 'role' => 'member'])->assertForbidden();
        $as->postJson("{$base}/entries/{$entry->id}/approve")->assertForbidden();
        $as->postJson("{$base}/targets", ['period_type' => 'yearly', 'period_year' => 2026, 'target_co2e_kg' => 100])->assertForbidden();

        // Tetap bisa melihat
        $as->getJson($base)->assertOk()->assertJsonPath('data.current_user_role', 'viewer');
        $as->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.0.current_user_role', 'viewer');
    }

    public function test_changing_a_user_to_viewer_demotes_their_project_roles_with_audit_log(): void
    {
        $pm = User::factory()->create(['role' => 'pm']);
        $this->addMember($this->project, $pm, 'owner');
        $other = $this->makeProject($this->owner, 'PRJ-2');
        $this->addMember($other, $pm, 'member');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/users/{$pm->id}", ['role' => 'viewer'])->assertOk();

        $this->assertSame(['viewer', 'viewer'], ProjectMember::where('user_id', $pm->id)->orderBy('project_id')->pluck('role')->all());
        $logs = AuditLog::where('model_type', ProjectMember::class)->where('action', 'updated')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($this->admin->id, $logs[0]->user_id);
        $this->assertSame(['role' => 'viewer'], $logs[0]->new_values);
    }

    public function test_changing_the_only_owner_to_viewer_is_refused(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/users/{$this->owner->id}", ['role' => 'viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
        $this->assertStringContainsString('Project PRJ-1', $response->json('errors.role.0'));

        $this->assertSame('pm', $this->owner->fresh()->role);
        $this->assertSame('owner', $this->roleOf($this->owner));
    }

    public function test_viewer_owner_does_not_count_toward_the_minimum_of_one_owner(): void
    {
        // Data lama: viewer masih owner, tetapi tidak bisa mengelola; owner PM tidak boleh turun
        $this->addMember($this->project, $this->viewer, 'owner');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$this->owner->id}", ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_viewer_owners_are_not_asked_to_approve_entries(): void
    {
        $this->addMember($this->project, $this->viewer, 'owner');
        $member = User::factory()->create(['role' => 'pm']);
        $this->addMember($this->project, $member, 'member');
        $entry = $this->makeEntry($this->project, $member);

        $this->actingAs($member, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/entries/{$entry->id}/submit")->assertOk();

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(0, $this->viewer->notifications()->count());
    }

    public function test_migration_demotes_existing_viewer_memberships_but_keeps_a_sole_owner(): void
    {
        $this->addMember($this->project, $this->viewer, 'owner');
        $solo = Project::create(['name' => 'Solo', 'code' => 'SOLO', 'start_date' => '2026-01-01', 'created_by' => $this->viewer->id]);
        ProjectMember::create(['project_id' => $solo->id, 'user_id' => $this->viewer->id, 'role' => 'owner']);

        (require database_path('migrations/2026_10_15_000000_demote_viewer_project_memberships.php'))->up();

        $this->assertSame('viewer', $this->roleOf($this->viewer));
        // Satu-satunya owner dibiarkan (wewenangnya tetap dicabut lewat otorisasi); admin yang menunjuk owner baru
        $this->assertSame('owner', ProjectMember::where('project_id', $solo->id)->value('role'));
        $this->assertSame(1, AuditLog::where('model_type', ProjectMember::class)->count());
    }
}
