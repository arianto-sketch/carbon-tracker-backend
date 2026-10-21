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

        if ($current !== null && $current !== $reported) {
            $this->notifyAdmins(new BackupProblemNotification($problems));
        } elseif ($current === null && $reported !== null) {
            $this->notifyAdmins(new BackupRecoveredNotification($status->summary()));
        }

        // Dicatat setelah email terkirim: bila pengiriman gagal, dicoba lagi pada pemeriksaan berikutnya
        $current === null ? Cache::forget(self::REPORTED_KEY) : Cache::forever(self::REPORTED_KEY, $current);

        if ($problems === []) {
            $this->info($status->summary());

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }

    private function notifyAdmins(object $notification): void
    {
        $admins = User::query()->where('role', 'admin')->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            $this->warn('Tidak ada admin aktif untuk menerima email backup.');

            return;
        }

        Notification::sendNow($admins, $notification);
    }
}
