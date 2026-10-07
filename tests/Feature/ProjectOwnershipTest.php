<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Project wajib punya minimal satu owner: owner terakhir tidak bisa diturunkan, dan owner tidak bisa dihapus.
 */
class ProjectOwnershipTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member, 'member');
    }

    private function changeRole(User $as, User $target, string $role)
    {
        return $this->actingAs($as, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$target->id}", ['role' => $role]);
    }

    private function roleOf(User $user): ?string
    {
        return ProjectMember::where('project_id', $this->project->id)->where('user_id', $user->id)->value('role');
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $this->changeRole($this->owner, $this->owner, 'member')
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->changeRole($this->admin, $this->owner, 'viewer')->assertStatus(422);

        $this->assertSame('owner', $this->roleOf($this->owner));
    }

    public function test_owner_can_hand_over_by_promoting_someone_first(): void
    {
        $this->changeRole($this->owner, $this->member, 'owner')->assertOk();
        $this->changeRole($this->owner, $this->owner, 'member')->assertOk();

        $this->assertSame('owner', $this->roleOf($this->member));
        $this->assertSame('member', $this->roleOf($this->owner));
    }

    public function test_keeping_owner_role_is_allowed_for_the_last_owner(): void
    {
        $this->changeRole($this->owner, $this->owner, 'owner')->assertOk();
    }

    public function test_co_owner_demoted_earlier_no_longer_counts(): void
    {
        // Owner lain sudah diturunkan oleh request yang commit lebih dulu
        $this->addMember($this->project, $other = User::factory()->create(), 'owner');
        ProjectMember::where('project_id', $this->project->id)->where('user_id', $other->id)->update(['role' => 'member']);

        $this->assertThrows(
            fn () => app(ProjectService::class)->updateMemberRole($this->project, $this->owner->id, 'member'),
            ValidationException::class,
        );

        $this->assertSame('owner', $this->roleOf($this->owner));
    }

    public function test_one_of_two_owners_can_be_demoted(): void
    {
        $this->addMember($this->project, $other = User::factory()->create(), 'owner');

        $this->changeRole($this->owner, $other, 'viewer')->assertOk();

        $this->assertSame('owner', $this->roleOf($this->owner));
        $this->assertSame('viewer', $this->roleOf($other));
    }

    public function test_changing_role_of_a_non_member_is_not_found(): void
    {
        $this->changeRole($this->owner, User::factory()->create(), 'member')->assertNotFound();
    }

    public function test_owner_cannot_be_removed(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$this->owner->id}")
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$this->member->id}")
            ->assertOk();
    }
}
