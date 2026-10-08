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

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
