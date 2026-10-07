<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\CarbonEntryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Dua request yang membaca entri bersamaan: yang kedua memegang model dengan status lama.
 * Service wajib mengecek ulang status dari database, bukan dari model yang dibawa request.
 */
class ConcurrentEntryActionsTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    private CarbonEntryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member, 'member');
        $this->service = app(CarbonEntryService::class);
    }

    public function test_approve_after_concurrent_reject_is_refused(): void
    {
        $entry = $this->makeEntry($this->project, $this->member, 'submitted');
        $stale = $entry->fresh();
        $this->service->reject($entry->fresh(), $this->admin, 'Faktor salah.');

        $this->assertThrows(fn () => $this->service->approve($stale, $this->owner), ValidationException::class);

        $fresh = $entry->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Faktor salah.', $fresh->rejection_reason);
        $this->assertNull($fresh->approved_by);
    }

    public function test_reject_after_concurrent_approve_is_refused(): void
    {
        $entry = $this->makeEntry($this->project, $this->member, 'submitted');
        $stale = $entry->fresh();
        $this->service->approve($entry->fresh(), $this->admin);

        $this->assertThrows(fn () => $this->service->reject($stale, $this->owner, 'Terlambat.'), ValidationException::class);

        $fresh = $entry->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_review_rechecks_four_eyes_rule_on_the_locked_row(): void
    {
        // Request review membaca entri sebelum owner mengubah isinya lalu submit ulang
        $entry = $this->makeEntry($this->project, $this->member, 'submitted');
        $stale = $entry->fresh();
        CarbonEntry::whereKey($entry->id)->update(['updated_by' => $this->owner->id]);

        $this->assertThrows(fn () => $this->service->approve($stale, $this->owner), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service->reject($stale, $this->owner, 'Tolak.'), AuthorizationException::class);

        $this->assertSame('submitted', $entry->fresh()->status);
    }

    public function test_submit_cannot_revert_an_entry_approved_meanwhile(): void
    {
        $entry = $this->makeEntry($this->project, $this->member, 'draft');
        $stale = $entry->fresh();
        $this->service->submit($entry->fresh(), $this->member);
        $this->service->approve($entry->fresh(), $this->owner);

        $this->assertThrows(fn () => $this->service->submit($stale, $this->member), ValidationException::class);

        $this->assertSame('approved', $entry->fresh()->status);
    }

    public function test_update_cannot_change_an_entry_submitted_meanwhile(): void
    {
        $entry = $this->makeEntry($this->project, $this->member, 'draft');
        $stale = $entry->fresh();
        $this->service->submit($entry->fresh(), $this->member);

        $this->assertThrows(fn () => $this->service->update($stale, [
            'emission_factor_id' => $this->gasolineFactor()->id,
            'quantity'           => 999,
            'entry_date'         => '2026-03-15',
        ], $this->member), ValidationException::class);

        $this->assertEquals(100, (float) $entry->fresh()->quantity);
    }

    public function test_delete_cannot_remove_an_entry_approved_meanwhile(): void
    {
        $entry = $this->makeEntry($this->project, $this->member, 'draft');
        $stale = $entry->fresh();
        CarbonEntry::whereKey($entry->id)->update(['status' => 'approved']);

        $this->assertThrows(fn () => $this->service->delete($stale), ValidationException::class);

        $this->assertNotNull(CarbonEntry::find($entry->id));
    }

    public function test_attachment_cannot_be_changed_on_an_entry_approved_meanwhile(): void
    {
        Storage::fake('local');
        $entry = $this->makeEntry($this->project, $this->member, 'draft');
        $stale = $entry->fresh();
        CarbonEntry::whereKey($entry->id)->update(['status' => 'approved']);

        $this->assertThrows(
            fn () => $this->service->attach($stale, UploadedFile::fake()->create('struk.pdf', 10, 'application/pdf'), $this->member),
            ValidationException::class,
        );

        $this->assertNull($entry->fresh()->attachment_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_attachment_update_does_not_leave_an_orphan_file(): void
    {
        Storage::fake('local');
        $entry = $this->makeEntry($this->project, $this->member, 'draft');
        CarbonEntry::updating(fn () => throw new \RuntimeException('audit log gagal'));

        $this->assertThrows(
            fn () => $this->service->attach($entry, UploadedFile::fake()->create('struk.pdf', 10, 'application/pdf'), $this->member),
            \RuntimeException::class,
        );

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_action_on_an_entry_deleted_meanwhile_is_not_found(): void
    {
        $entry = $this->makeEntry($this->project, $this->member, 'submitted');
        $stale = $entry->fresh();
        CarbonEntry::whereKey($entry->id)->first()->delete();

        $this->assertThrows(fn () => $this->service->approve($stale, $this->owner), ModelNotFoundException::class);
    }
}
