<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use App\Notifications\EntryBatchNotification;
use App\Notifications\EntryWorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Owner meng-approve/menolak banyak entri sekaligus. Aturan per entri tetap berlaku (status submitted,
 * prinsip 4 mata); yang tidak memenuhi dilewati dengan alasan. Pembuat menerima satu ringkasan.
 */
class BulkReviewTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $memberA;

    private User $memberB;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['role' => 'pm', 'name' => 'Owner Satu']);
        $this->memberA = User::factory()->create(['role' => 'pm']);
        $this->memberB = User::factory()->create(['role' => 'pm']);
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->memberA);
        $this->addMember($this->project, $this->memberB);
    }

    private function review(User $as, array $payload): TestResponse
    {
        return $this->actingAs($as, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/entries/bulk-review", $payload);
    }

    public function test_owner_approves_many_entries_and_ineligible_ones_are_skipped_with_reasons(): void
    {
        $a1 = $this->makeEntry($this->project, $this->memberA, 'submitted');
        $a2 = $this->makeEntry($this->project, $this->memberA, 'submitted');
        $b1 = $this->makeEntry($this->project, $this->memberB, 'submitted');
        $draft = $this->makeEntry($this->project, $this->memberA, 'draft');
        $own = $this->makeEntry($this->project, $this->owner, 'submitted');

        $response = $this->review($this->owner, ['action' => 'approve', 'entry_ids' => [$a1->id, $a2->id, $b1->id, $draft->id, $own->id]])
            ->assertOk()
            ->assertJsonPath('message', '3 entri disetujui, 2 dilewati.')
            ->assertJsonCount(2, 'data.skipped');

        $this->assertEqualsCanonicalizing([$a1->id, $a2->id, $b1->id], $response->json('data.processed'));
        $skipped = collect($response->json('data.skipped'))->keyBy('id');
        $this->assertStringContainsString('submitted', $skipped[$draft->id]['reason']);
        $this->assertStringContainsString('sendiri', $skipped[$own->id]['reason']);

        $this->assertSame('approved', $a1->fresh()->status);
        $this->assertSame($this->owner->id, $a1->fresh()->approved_by);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('submitted', $own->fresh()->status);
    }

    public function test_bulk_rejection_requires_a_reason_and_records_it(): void
    {
        $a1 = $this->makeEntry($this->project, $this->memberA, 'submitted');
        $b1 = $this->makeEntry($this->project, $this->memberB, 'submitted');

        $this->review($this->owner, ['action' => 'reject', 'entry_ids' => [$a1->id, $b1->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->review($this->owner, ['action' => 'reject', 'entry_ids' => [$a1->id, $b1->id], 'reason' => 'Struk belum dilampirkan.'])
            ->assertOk()
            ->assertJsonPath('message', '2 entri ditolak.');

        $this->assertSame('rejected', $a1->fresh()->status);
        $this->assertSame('Struk belum dilampirkan.', $b1->fresh()->rejection_reason);
    }

    public function test_each_creator_gets_one_summary_instead_of_one_notification_per_entry(): void
    {
        Notification::fake();
        $a1 = $this->makeEntry($this->project, $this->memberA, 'submitted');
        $a2 = $this->makeEntry($this->project, $this->memberA, 'submitted');
        $b1 = $this->makeEntry($this->project, $this->memberB, 'submitted');

        $this->review($this->owner, ['action' => 'approve', 'entry_ids' => [$a1->id, $a2->id, $b1->id]])->assertOk();

        Notification::assertSentToTimes($this->memberA, EntryBatchNotification::class, 1);
        Notification::assertNotSentTo($this->memberA, EntryWorkflowNotification::class);
        Notification::assertSentTo($this->memberA, EntryBatchNotification::class, function (EntryBatchNotification $n) {
            return $n->toArray($this->memberA)['message'] === 'Owner Satu menyetujui 2 entri Anda di project Project PRJ-1.';
        });
        // Satu entri saja: notifikasi biasa (tautan ke entri tetap ada)
        Notification::assertSentToTimes($this->memberB, EntryWorkflowNotification::class, 1);
    }

    public function test_entries_from_other_projects_are_skipped(): void
    {
        $other = $this->makeProject($this->owner, 'PRJ-2');
        $foreign = $this->makeEntry($other, $this->memberA, 'submitted');

        $this->review($this->owner, ['action' => 'approve', 'entry_ids' => [$foreign->id]])
            ->assertOk()
            ->assertJsonPath('data.processed', [])
            ->assertJsonPath('data.skipped.0.reason', 'Entri tidak ditemukan di project ini.');

        $this->assertSame('submitted', CarbonEntry::findOrFail($foreign->id)->status);
    }

    public function test_only_project_owners_or_admins_can_review_in_bulk(): void
    {
        $entry = $this->makeEntry($this->project, $this->memberB, 'submitted');
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->addMember($this->project, $viewer, 'viewer');

        $this->review($this->memberA, ['action' => 'approve', 'entry_ids' => [$entry->id]])->assertForbidden();
        $this->review($viewer, ['action' => 'approve', 'entry_ids' => [$entry->id]])->assertForbidden();
        $this->review($this->admin, ['action' => 'approve', 'entry_ids' => [$entry->id]])->assertOk();
    }

    public function test_an_unexpected_failure_on_one_entry_does_not_stop_the_others_or_their_notifications(): void
    {
        Notification::fake();
        $failing = $this->makeEntry($this->project, $this->memberA, 'submitted');
        $ok = $this->makeEntry($this->project, $this->memberA, 'submitted');
        // Mis. deadlock/lock wait timeout pada satu baris
        CarbonEntry::updating(function (CarbonEntry $entry) use ($failing) {
            if ($entry->id === $failing->id && $entry->status === 'approved') {
                throw new \RuntimeException('Deadlock found when trying to get lock');
            }
        });

        $this->review($this->owner, ['action' => 'approve', 'entry_ids' => [$failing->id, $ok->id]])
            ->assertOk()
            ->assertJsonPath('data.processed', [$ok->id])
            ->assertJsonPath('data.skipped.0.reason', 'Gagal diproses, coba lagi.');

        $this->assertSame('submitted', $failing->fresh()->status);
        Notification::assertSentToTimes($this->memberA, EntryWorkflowNotification::class, 1);
    }

    public function test_approval_does_not_carry_a_reason(): void
    {
        Notification::fake();
        $entry = $this->makeEntry($this->project, $this->memberA, 'submitted');

        $this->review($this->owner, ['action' => 'approve', 'entry_ids' => [$entry->id], 'reason' => 'tidak relevan'])->assertOk();

        Notification::assertSentTo($this->memberA, EntryWorkflowNotification::class,
            fn (EntryWorkflowNotification $n) => $n->toArray($this->memberA)['reason'] === null);
    }

    public function test_duplicate_ids_and_viewer_owners_are_refused(): void
    {
        $entry = $this->makeEntry($this->project, $this->memberB, 'submitted');
        $this->review($this->owner, ['action' => 'approve', 'entry_ids' => [$entry->id, $entry->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_ids.0');

        $viewerOwner = User::factory()->create(['role' => 'viewer']);
        $this->addMember($this->project, $viewerOwner, 'owner');
        $this->review($viewerOwner, ['action' => 'approve', 'entry_ids' => [$entry->id]])->assertForbidden();
    }

    public function test_request_is_validated(): void
    {
        $this->review($this->owner, ['action' => 'hapus', 'entry_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action', 'entry_ids']);

        $this->review($this->owner, ['action' => 'approve', 'entry_ids' => range(1, 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_ids');
    }
}
