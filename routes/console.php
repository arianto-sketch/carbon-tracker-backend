<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// File temp Laravel Excel tertinggal saat import gagal dibaca; hapus yang lebih tua dari 1 hari
Schedule::call(function () {
    $dir = config('excel.temporary_files.local_path', storage_path('framework/cache/laravel-excel'));
    $cutoff = now()->subDay()->getTimestamp();

    foreach (glob($dir.'/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
})->daily()->name('cleanup-laravel-excel-temp');

// File lampiran dari entri yang sudah dihapus lebih dari 30 hari
Schedule::command('entries:purge-deleted-attachments')->daily();

// Token reset password yang sudah kedaluwarsa
Schedule::command('auth:clear-resets')->daily();

// Nilai faktor "saat ini" mengikuti periode yang mulai berlaku hari ini (tengah malam WIB)
Schedule::command('factors:sync-current-values')->dailyAt('00:05')->timezone(config('app.business_timezone'));

// Email ringkasan emisi bulan lalu untuk owner project & admin (tanggal 1, pagi WIB)
Schedule::command('reports:send-monthly-summaries')
    ->monthlyOn(1, '07:00')
    ->timezone(config('app.business_timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Status backup harian dan off-site (ditulis service backup/offsite); email admin saat bermasalah dan saat pulih
Schedule::command('backups:check')->hourly()->withoutOverlapping()->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
