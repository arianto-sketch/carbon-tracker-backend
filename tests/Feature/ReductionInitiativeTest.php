<?php

namespace Tests\Feature;

use App\Models\EmissionCategory;
use App\Models\Project;
use App\Models\ReductionInitiative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Rencana aksi reduksi emisi per project: inisiatif dengan estimasi penghematan, status, dan PIC.
 */
class ReductionInitiativeTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private User $viewer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['role' => 'pm']);
        $this->member = User::factory()->create(['role' => 'pm', 'name' => 'Rina']);
        $this->viewer = User::factory()->create(['role' => 'pm']);
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member);
        $this->addMember($this->project, $this->viewer, 'viewer');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/initiatives{$suffix}";
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'                  => 'Ganti lampu kantor ke LED',
            'description'            => 'Semua lantai 2',
            'category_id'            => EmissionCategory::where('slug', 'energy')->value('id'),
            'estimated_reduction_kg' => 1200,
            'status'                 => 'planned',
            'pic_user_id'            => $this->member->id,
            'target_date'            => '2026-12-31',
        ], $overrides);
    }

    public function test_members_add_initiatives_and_the_list_shows_totals(): void
    {
        $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Ganti lampu kantor ke LED')
            ->assertJsonPath('data.pic.name', 'Rina')
            ->assertJsonPath('data.status', 'planned');
        $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload(['title' => 'Carpool', 'estimated_reduction_kg' => 300, 'status' => 'in_progress']))
            ->assertCreated();

        $this->actingAs($this->viewer, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.planned_kg', 1500)
            ->assertJsonPath('meta.done_kg', 0);
    }

    public function test_marking_done_records_completion_and_moves_the_total(): void
    {
        $id = $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload())->json('data.id');

        $this->actingAs($this->member, 'sanctum')->putJson($this->url("/{$id}"), $this->payload(['status' => 'done']))
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertNotNull(ReductionInitiative::findOrFail($id)->completed_at);
        $this->actingAs($this->owner, 'sanctum')->getJson($this->url())
            ->assertJsonPath('meta.planned_kg', 0)
            ->assertJsonPath('meta.done_kg', 1200);
    }

    public function test_viewers_and_outsiders_cannot_change_initiatives(): void
    {
        $this->actingAs($this->viewer, 'sanctum')->postJson($this->url(), $this->payload())->assertForbidden();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($this->url())->assertForbidden();
    }

    public function test_pic_must_be_an_active_project_member(): void
    {
        $outsider = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $this->addMember($this->project, $inactive);

        foreach ([$outsider, $inactive] as $pic) {
            $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload(['pic_user_id' => $pic->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('pic_user_id');
        }
    }

    public function test_outsiders_are_rejected_before_validation(): void
    {
        // 403, bukan 422: pesan validasi PIC tidak boleh membocorkan siapa anggota project
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson($this->url(), $this->payload(['title' => '', 'pic_user_id' => $this->member->id]))
            ->assertForbidden()
            ->assertJsonPath('message', 'Hanya owner atau member project yang bisa mengelola rencana aksi.');
    }

    public function test_editing_keeps_a_pic_who_has_left_the_project(): void
    {
        $id = $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload())->json('data.id');
        $this->project->projectMembers()->where('user_id', $this->member->id)->delete();

        $this->actingAs($this->owner, 'sanctum')->putJson($this->url("/{$id}"), $this->payload(['status' => 'in_progress']))
            ->assertOk()
            ->assertJsonPath('data.pic.name', 'Rina');
    }

    public function test_completion_date_is_kept_on_resave_and_cleared_when_reopened(): void
    {
        $id = $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload(['status' => 'done']))->json('data.id');
        $completedAt = ReductionInitiative::findOrFail($id)->completed_at;

        $this->travel(2)->days();
        $this->actingAs($this->member, 'sanctum')->putJson($this->url("/{$id}"), $this->payload(['status' => 'done', 'title' => 'LED lantai 2']))->assertOk();
        $this->assertEquals($completedAt, ReductionInitiative::findOrFail($id)->completed_at);

        $this->actingAs($this->member, 'sanctum')->putJson($this->url("/{$id}"), $this->payload(['status' => 'in_progress']))->assertOk();
        $this->assertNull(ReductionInitiative::findOrFail($id)->completed_at);
    }

    public function test_initiatives_of_another_project_are_not_reachable(): void
    {
        $other = $this->makeProject($this->owner, 'PRJ-2');
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$other->id}/initiatives", $this->payload(['pic_user_id' => null]))
            ->json('data.id');

        $this->actingAs($this->member, 'sanctum')->putJson($this->url("/{$id}"), $this->payload())->assertNotFound();
        // 404 lebih dulu dari validasi
        $this->actingAs($this->member, 'sanctum')->putJson($this->url("/{$id}"), $this->payload(['title' => '']))->assertNotFound();
        $this->actingAs($this->member, 'sanctum')->putJson($this->url('/999999'), [])->assertNotFound();
    }

    public function test_only_owners_or_admins_delete_initiatives(): void
    {
        $id = $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload())->json('data.id');

        $this->actingAs($this->member, 'sanctum')->deleteJson($this->url("/{$id}"))->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->deleteJson($this->url("/{$id}"))->assertOk();
        $this->assertSoftDeleted('reduction_initiatives', ['id' => $id]);
    }

    public function test_input_is_validated(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->url(), $this->payload(['title' => '', 'estimated_reduction_kg' => -5, 'status' => 'selesai']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'estimated_reduction_kg', 'status']);
    }

    public function test_dashboard_sums_initiatives_of_accessible_projects(): void
    {
        $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload())->assertCreated();
        $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload(['title' => 'Panel surya', 'estimated_reduction_kg' => 800, 'status' => 'done']))->assertCreated();
        $this->actingAs($this->member, 'sanctum')->postJson($this->url(), $this->payload(['title' => 'Batal', 'estimated_reduction_kg' => 50, 'status' => 'cancelled']))->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/initiatives')
            ->assertOk()
            ->assertJsonPath('data.planned_kg', 1200)
            ->assertJsonPath('data.done_kg', 800)
            ->assertJsonPath('data.counts.planned', 1)
            ->assertJsonPath('data.counts.done', 1)
            ->assertJsonPath('data.counts.cancelled', 1);

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/dashboard/initiatives')
            ->assertJsonPath('data.planned_kg', 0);

        $other = $this->makeProject($this->owner, 'PRJ-2');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/dashboard/initiatives?project_id={$other->id}")
            ->assertJsonPath('data.planned_kg', 0)
            ->assertJsonPath('data.counts.done', 0);
    }
}
