<?php

namespace App\Console\Commands;

use App\Models\CarbonEntry;
use App\Services\CarbonEntryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * File lampiran tetap tersimpan saat entri di-soft-delete (masih bisa dipulihkan atau diaudit).
 * Setelah lewat masa simpan, file dihapus dari disk dan path-nya dikosongkan.
 */
class PurgeDeletedEntryAttachments extends Command
{
    protected $signature = 'entries:purge-deleted-attachments {--days=30 : Masa simpan lampiran setelah entri dihapus (hari)}';

    protected $description = 'Hapus file lampiran dari entri yang sudah dihapus lebih dari N hari';

    public function handle(CarbonEntryService $entries): int
    {
        $days = max(1, (int) $this->option('days'));
        $disk = Storage::disk($entries->attachmentDisk());
        $purged = 0;

        CarbonEntry::onlyTrashed()
            ->whereNotNull('attachment_path')
            ->where('deleted_at', '<', now()->subDays($days))
            ->chunkById(100, function ($trashed) use ($disk, &$purged) {
                foreach ($trashed as $entry) {
                    $disk->delete($entry->attachment_path);
                    // Lewat query builder: entri sudah dihapus, tidak perlu riwayat perubahan baru
                    CarbonEntry::withTrashed()->whereKey($entry->id)->update(['attachment_path' => null]);
                    $purged++;
                }
            });

        $this->info("{$purged} lampiran dari entri yang dihapus lebih dari {$days} hari dibersihkan.");

        return self::SUCCESS;
    }
}
