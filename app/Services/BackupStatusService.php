<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Membaca status yang ditulis docker/backup/backup.sh (backup.json) dan docker/offsite/offsite.sh
 * (offsite.json) lalu merangkum masalahnya. Waktu di file status dalam UTC (ISO 8601).
 */
class BackupStatusService
{
    public function configured(): bool
    {
        return filled(config('backups.status_path'));
    }

    /**
     * Masalah yang sedang terjadi, urut backup lalu off-site; kunci dipakai untuk mengenali kejadian baru.
     *
     * @return array<string, string> kode => penjelasan
     */
    public function problems(): array
    {
        $problems = [];
        $backup = $this->read('backup');
        $maxAge = (int) config('backups.max_age_hours');

        if ($backup === null) {
            $problems['backup_never'] = 'Belum ada catatan backup harian. Service backup belum pernah berjalan atau volume status tidak terpasang.';
        } else {
            $lastSuccess = $this->time($backup['last_success_at'] ?? null);
            $lastAttempt = $this->time($backup['last_attempt_at'] ?? null);

            if (filled($backup['last_error'] ?? null) && $lastAttempt && (! $lastSuccess || $lastAttempt->gt($lastSuccess))) {
                $problems['backup_failed'] = "Backup harian {$this->format($lastAttempt)} gagal: {$backup['last_error']}";
            }
            if (! $lastSuccess || $lastSuccess->lt(now()->subHours($maxAge))) {
                $problems['backup_stale'] = 'Tidak ada backup harian yang berhasil dalam '.$maxAge.' jam terakhir'
                    .($lastSuccess ? " (terakhir {$this->format($lastSuccess)})." : '.');
            }
        }

        $offsite = $this->read('offsite');
        if ($offsite === null) {
            $problems['offsite_missing'] = 'Service offsite tidak mencatat status (container tidak berjalan?).';
        } elseif (($offsite['enabled'] ?? false) === true) {
            $offsiteMaxAge = (int) config('backups.offsite_max_age_hours');
            $lastSuccess = $this->time($offsite['last_success_at'] ?? null);

            if (! $lastSuccess || $lastSuccess->lt(now()->subHours($offsiteMaxAge))) {
                $problems['offsite_stale'] = 'Salinan off-site tidak berhasil diperbarui dalam '.$offsiteMaxAge.' jam terakhir'
                    .($lastSuccess ? " (terakhir {$this->format($lastSuccess)})" : '')
                    .(filled($offsite['last_error'] ?? null) ? ". Error terakhir: {$offsite['last_error']}" : '.');
            }
        }

        return $problems;
    }

    /** Ringkasan satu baris untuk output command saat semuanya normal */
    public function summary(): string
    {
        $backup = $this->time($this->read('backup')['last_success_at'] ?? null);
        $offsite = $this->read('offsite');
        $offsiteText = ($offsite['enabled'] ?? false) === true
            ? 'off-site terakhir '.$this->format($this->time($offsite['last_success_at'] ?? null))
            : 'off-site nonaktif';

        return 'Backup normal: harian terakhir '.$this->format($backup).", {$offsiteText}.";
    }

    /** @return array<string, mixed>|null null bila file tidak ada atau tidak bisa dibaca */
    private function read(string $name): ?array
    {
        $file = rtrim((string) config('backups.status_path'), '/')."/{$name}.json";
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function format(?Carbon $time): string
    {
        return $time
            ? $time->clone()->setTimezone(config('app.business_timezone'))->format('d M Y H:i').' WIB'
            : '-';
    }
}
