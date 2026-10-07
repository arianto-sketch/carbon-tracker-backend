<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class PurgeDeletedEntryAttachmentsTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedMasterData();
    }

    private function entryWithAttachment(?int $deletedDaysAgo): CarbonEntry
    {
        $owner = User::factory()->create();
        $entry = $this->makeEntry($this->makeProject($owner, 'P-'.$owner->id), $owner, 'draft');
        $path = "attachments/{$entry->project_id}/{$entry->id}/bukti.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4');
        $entry->update(['attachment_path' => $path, 'attachment_name' => 'bukti.pdf']);

        if ($deletedDaysAgo !== null) {
            $entry->delete();
            CarbonEntry::withTrashed()->whereKey($entry->id)->update(['deleted_at' => now()->subDays($deletedDaysAgo)]);
        }

        return $entry;
    }

    public function test_purges_attachments_of_entries_deleted_more_than_30_days_ago(): void
    {
        $old = $this->entryWithAttachment(31);
        $recent = $this->entryWithAttachment(29);
        $active = $this->entryWithAttachment(null);
        $oldPath = $old->attachment_path;

        $this->artisan('entries:purge-deleted-attachments')
            ->expectsOutputToContain('1 lampiran')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing($oldPath);
        $purged = CarbonEntry::withTrashed()->find($old->id);
        $this->assertNull($purged->attachment_path);
        $this->assertNull($purged->attachment_name, 'Sama seperti hapus lampiran manual');
        Storage::disk('local')->assertExists($recent->attachment_path);
        Storage::disk('local')->assertExists($active->attachment_path);
        $this->assertNotNull(CarbonEntry::withTrashed()->find($recent->id)->attachment_path);
    }

    public function test_path_is_kept_when_the_file_cannot_be_deleted(): void
    {
        $old = $this->entryWithAttachment(31);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->andReturn(false);
        $disk->shouldReceive('exists')->andReturn(true);
        Storage::shouldReceive('disk')->andReturn($disk);

        // Path dipertahankan supaya bisa dicoba lagi di jadwal berikutnya
        $this->artisan('entries:purge-deleted-attachments')
            ->expectsOutputToContain('1 gagal')
            ->assertFailed();

        $this->assertNotNull(CarbonEntry::withTrashed()->find($old->id)->attachment_path);
    }

    public function test_purge_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'entries:purge-deleted-attachments'));

        $this->assertNotNull($event, 'Command belum dijadwalkan');
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
