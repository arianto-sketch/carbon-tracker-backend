<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class ApprovalNotificationTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $coOwner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['name' => 'Owner Satu']);
        $this->coOwner = User::factory()->create();
        $this->member = User::factory()->create(['name' => 'Member Dua']);
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->coOwner, 'owner');
        $this->addMember($this->project, $this->member, 'member');
    }

    private function entryUrl(CarbonEntry $entry, string $action): string
    {
        return "/api/v1/projects/{$this->project->id}/entries/{$entry->id}/{$action}";
    }

    public function test_submitting_notifies_project_owners_but_not_the_submitter(): void
    {
        $entry = $this->makeEntry($this->project, $this->member);

        $this->actingAs($this->member, 'sanctum')->postJson($this->entryUrl($entry, 'submit'))->assertOk();

        foreach ([$this->owner, $this->coOwner] as $owner) {
            $this->assertSame(1, $owner->notifications()->count());
            $data = $owner->notifications()->first()->data;
            $this->assertSame('entry_submitted', $data['type']);
            $this->assertSame($entry->id, $data['entry_id']);
            $this->assertSame('Member Dua', $data['actor_name']);
        }
        $this->assertSame(0, $this->member->notifications()->count());
    }

    public function test_owner_submitting_own_entry_does_not_notify_themselves(): void
    {
        $entry = $this->makeEntry($this->project, $this->owner);

        $this->actingAs($this->owner, 'sanctum')->postJson($this->entryUrl($entry, 'submit'))->assertOk();

        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(1, $this->coOwner->notifications()->count());
    }

    public function test_approval_and_rejection_notify_the_creator(): void
    {
        $approved = $this->makeEntry($this->project, $this->member, 'submitted');
        $rejected = $this->makeEntry($this->project, $this->member, 'submitted');

        $this->actingAs($this->owner, 'sanctum')->postJson($this->entryUrl($approved, 'approve'))->assertOk();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->entryUrl($rejected, 'reject'), ['reason' => 'Struk tidak terbaca.'])->assertOk();

        $types = $this->member->notifications()->get()->pluck('data.type')->sort()->values()->all();
        $this->assertSame(['entry_approved', 'entry_rejected'], $types);

        $rejection = $this->member->notifications()->get()->firstWhere('data.type', 'entry_rejected');
        $this->assertSame('Struk tidak terbaca.', $rejection->data['reason']);
        $this->assertSame('Owner Satu', $rejection->data['actor_name']);
    }

    public function test_user_lists_only_own_notifications_with_unread_count(): void
    {
        $entry = $this->makeEntry($this->project, $this->member);
        $this->actingAs($this->member, 'sanctum')->postJson($this->entryUrl($entry, 'submit'))->assertOk();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.data.type', 'entry_submitted')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('meta.unread_count', 1);

        $this->actingAs($this->member, 'sanctum')->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_mark_one_and_all_notifications_as_read(): void
    {
        foreach (range(1, 2) as $_) {
            $entry = $this->makeEntry($this->project, $this->member);
            $this->actingAs($this->member, 'sanctum')->postJson($this->entryUrl($entry, 'submit'))->assertOk();
        }
        $first = $this->owner->notifications()->first();
        $this->assertNotNull($first, 'Owner seharusnya menerima notifikasi submit.');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/notifications/{$first->id}/read")->assertOk();
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertSame(1, $this->owner->unreadNotifications()->count());

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->owner->unreadNotifications()->count());
    }

    public function test_cannot_mark_another_users_notification(): void
    {
        $entry = $this->makeEntry($this->project, $this->member);
        $this->actingAs($this->member, 'sanctum')->postJson($this->entryUrl($entry, 'submit'))->assertOk();
        $ownersNotification = $this->owner->notifications()->first();
        $this->assertNotNull($ownersNotification, 'Owner seharusnya menerima notifikasi submit.');

        $this->actingAs($this->member, 'sanctum')
            ->postJson("/api/v1/notifications/{$ownersNotification->id}/read")
            ->assertNotFound();

        $this->assertNull($ownersNotification->fresh()->read_at);
    }
}
