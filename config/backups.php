<?php

return [

    /*
     * Folder berisi backup.json dan offsite.json yang ditulis service backup dan offsite
     * (compose.yml: volume backup_status). Kosong = pemeriksaan `backups:check` dilewati,
     * mis. development tanpa Docker.
     */
    'status_path' => env('BACKUP_STATUS_PATH'),

    // Backup harian dianggap terlambat bila tidak ada yang berhasil selama ini (jam)
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),

    // Sinkron off-site berjalan tiap 30 menit; gagal sesaat (jaringan) baru dilaporkan setelah ini (jam)
    'offsite_max_age_hours' => (int) env('OFFSITE_MAX_AGE_HOURS', 6),

];
