<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\BackupProblemNotification;
use App\Notifications\BackupRecoveredNotification;
use App\Services\BackupStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Dijalankan scheduler tiap jam. Email ke admin aktif hanya saat kumpulan masalah berubah (masalah
 * baru muncul), lalu sekali saat semuanya pulih, bukan setiap jam selama masalah berlangsung.
 * Masalah yang sudah dilaporkan dicatat di cache: setelah `cache:clear`, masalah yang masih
 * berlangsung dilaporkan sekali lagi.
 */
class CheckBackups extends Command
{
    protected $signature = 'backups:check';

    protected $description = 'Periksa status backup harian dan off-site; email admin bila bermasalah';

    /** Kode masalah yang terakhir dilaporkan (null = tidak ada) */
    private const REPORTED_KEY = 'backups:reported-problems';

    public function handle(BackupStatusService $status): int
    {
        if (! $status->configured()) {
            $this->info('BACKUP_STATUS_PATH belum diisi: pemeriksaan backup dilewati.');

            return self::SUCCESS;
        }

        $problems = $status->problems();
        $current = $problems === [] ? null : implode(',', array_keys($problems));
        $reported = Cache::get(self::REPORTED_KEY);

        $isNew = array_diff(array_keys($problems), explode(',', (string) $reported)) !== [];

        if ($current !== null && $isNew) {
            // Dicatat hanya bila email sampai ke admin: gagal kirim (exception) atau belum ada admin
            // aktif berarti dilaporkan lagi pada pemeriksaan berikutnya
            if ($this->notifyAdmins(new BackupProblemNotification($problems))) {
                Cache::forever(self::REPORTED_KEY, $current);
            }
        } elseif ($current !== null) {
            // Sebagian masalah yang sudah dilaporkan pulih, yang lain masih ada: tanpa email baru
            Cache::forever(self::REPORTED_KEY, $current);
        } elseif ($reported !== null) {
            $this->notifyAdmins(new BackupRecoveredNotification($status->summary()));
            Cache::forget(self::REPORTED_KEY);
        }

        if ($problems === []) {
            $this->info($status->summary());

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }

    /** @return bool false bila tidak ada admin aktif yang bisa menerima */
    private function notifyAdmins(object $notification): bool
    {
        $admins = User::query()->where('role', 'admin')->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            $this->warn('Tidak ada admin aktif untuk menerima email backup.');

            return false;
        }

        Notification::sendNow($admins, $notification);

        return true;
    }
}
