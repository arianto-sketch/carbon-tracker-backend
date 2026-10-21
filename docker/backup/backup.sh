#!/usr/bin/env bash
# Backup harian database (mysqldump) dan lampiran, dengan masa simpan BACKUP_RETENTION_DAYS hari.
# Dijalankan di service "backup" (image mysql, jadi versi mysqldump sama dengan server).
#
#   schedule        jalan terus: backup tiap hari pukul BACKUP_TIME_UTC (default)
#   run             backup sekarang, lalu hapus backup yang sudah lewat masa simpan
#   restore FILE    pulihkan database persis seperti FILE (db-*.sql.gz); backup dulu kondisi saat ini
#   stream FILE     kirim isi arsip lampiran (storage-*.tar.gz) ke stdout, untuk make restore-storage
#   list            daftar file backup
#
# Hasil schedule/run dicatat di STATUS_DIR/backup.json (volume backup_status), dibaca
# `php artisan backups:check` di scheduler untuk email peringatan ke admin.
set -uo pipefail
# File backup berisi data pribadi (email, hash password): hanya bisa dibaca pemiliknya
umask 077

BACKUP_DIR="${BACKUP_DIR:-/backups}"
STORAGE_DIR="${STORAGE_DIR:-/storage}"
STATUS_DIR="${STATUS_DIR:-/status}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_TIME_UTC="${BACKUP_TIME_UTC:-19:00}"
DB_NAME="${DB_DATABASE:?DB_DATABASE belum diisi}"
# Lewat env, bukan argumen: password tidak terlihat di daftar proses
export MYSQL_PWD="${DB_PASSWORD:?DB_PASSWORD belum diisi}"
MYSQL_ARGS=(-h "${DB_HOST:-mysql}" -u "${DB_USERNAME:?DB_USERNAME belum diisi}")

log() { echo "[backup] $(date -u '+%Y-%m-%d %H:%M:%S') UTC $*"; }
die() { echo "$*" >&2; exit 1; }

# Tanpa nol di depan: "08" akan dibaca bash sebagai bilangan oktal yang tidak valid
if ! [[ "$RETENTION_DAYS" =~ ^[1-9][0-9]*$ ]]; then
    die "BACKUP_RETENTION_DAYS harus bilangan bulat >= 1 (sekarang: ${RETENTION_DAYS})"
fi

backup_database() {
    local file="${BACKUP_DIR}/db-$1.sql.gz"
    # --single-transaction: snapshot konsisten tanpa mengunci tabel InnoDB
    if ! mysqldump "${MYSQL_ARGS[@]}" --single-transaction --quick --no-tablespaces --triggers \
        --set-gtid-purged=OFF "$DB_NAME" | gzip -6 > "${file}.tmp"; then
        rm -f "${file}.tmp"
        log "GAGAL: mysqldump ${DB_NAME}"
        return 1
    fi
    mv "${file}.tmp" "$file"
    log "database -> $(basename "$file") ($(du -h "$file" | cut -f1))"
}

backup_storage() {
    local file="${BACKUP_DIR}/storage-$1.tar.gz" status
    if [ ! -d "$STORAGE_DIR" ]; then
        log "lampiran dilewati: ${STORAGE_DIR} tidak di-mount"
        return 0
    fi
    tar -czf "${file}.tmp" -C "$STORAGE_DIR" .
    status=$?
    # Kode 1 = ada file yang berubah saat dibaca (mis. laporan baru dibuat); arsip tetap terpakai
    if (( status > 1 )); then
        rm -f "${file}.tmp"
        log "GAGAL: arsip lampiran (tar keluar dengan kode ${status})"
        return 1
    fi
    (( status == 1 )) && log "peringatan: ada lampiran yang berubah saat diarsip"
    mv "${file}.tmp" "$file"
    log "lampiran -> $(basename "$file") ($(du -h "$file" | cut -f1))"
}

# Simpan backup RETENTION_DAYS hari terakhir (termasuk hari ini); tanggal dibaca dari nama file.
# $1 = pola file (db-*.sql.gz / storage-*.tar.gz)
prune() {
    local cutoff name day f
    cutoff=$(date -u -d "-$((RETENTION_DAYS - 1)) days" +%Y%m%d)
    shopt -s nullglob
    for f in "$BACKUP_DIR"/$1; do
        name=$(basename "$f")
        day=${name#*-}
        day=${day:0:8}
        if [[ "$day" =~ ^[0-9]{8}$ && "$day" < "$cutoff" ]]; then
            rm -f -- "$f"
            log "hapus ${name} (lewat ${RETENTION_DAYS} hari)"
        fi
    done
}

run_backup() {
    local stamp status=0
    stamp=$(date -u +%Y%m%d-%H%M%S)
    mkdir -p "$BACKUP_DIR"
    # Sisa backup yang terputus (container dihentikan di tengah jalan)
    rm -f "$BACKUP_DIR"/*.tmp
    # Backup lama tiap jenis hanya dihapus bila backup baru jenis itu berhasil
    if backup_database "$stamp"; then prune 'db-*.sql.gz'; else status=1; fi
    if backup_storage "$stamp"; then prune 'storage-*.tar.gz'; else status=1; fi
    return "$status"
}

# Status tidak berisi data pribadi: bisa dibaca container scheduler (bukan root). Tanpa folder status, dilewati.
# $1 = kode keluar run_backup
write_status() {
    [ -d "$STATUS_DIR" ] || return 0
    local file="${STATUS_DIR}/backup.json" now last_success error=null
    now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    last_success=$(sed -n 's/.*"last_success_at": *"\([^"]*\)".*/\1/p' "$file" 2>/dev/null)
    if [ "$1" -eq 0 ]; then
        last_success=$now
    else
        error='"backup database atau lampiran gagal (lihat: make logs SERVICE=backup)"'
    fi
    # File sementara unik: jadwal dan `make backup` bisa menulis status bersamaan
    local tmp
    tmp=$(mktemp "${file}.XXXXXX") || return 1
    printf '{"last_attempt_at": "%s", "last_success_at": %s, "last_error": %s}\n' \
        "$now" "$( [ -n "$last_success" ] && echo "\"${last_success}\"" || echo null )" "$error" \
        > "$tmp" && chmod 644 "$tmp" && mv "$tmp" "$file"
}

backup_and_record() {
    local status
    run_backup
    status=$?
    write_status "$status"
    return "$status"
}

seconds_until_next_run() {
    local now target
    now=$(date -u +%s)
    target=$(date -u -d "today ${BACKUP_TIME_UTC}" +%s)
    if (( target <= now )); then
        target=$(date -u -d "tomorrow ${BACKUP_TIME_UTC}" +%s)
    fi
    echo $(( target - now ))
}

schedule() {
    # Berhenti segera saat container dihentikan (sleep dijalankan di background lalu ditunggu)
    trap 'log "berhenti"; exit 0' TERM INT
    log "backup harian pukul ${BACKUP_TIME_UTC} UTC, simpan ${RETENTION_DAYS} hari, di ${BACKUP_DIR}"
    while true; do
        local wait_for
        wait_for=$(seconds_until_next_run)
        log "backup berikutnya dalam $((wait_for / 3600)) jam $((wait_for % 3600 / 60)) menit"
        sleep "$wait_for" &
        wait $!
        backup_and_record || log "GAGAL: backup tidak lengkap, dicoba lagi pada jadwal berikutnya"
    done
}

# Nama file saja (tanpa path) di BACKUP_DIR, sesuai pola; arsip gzip harus utuh
backup_file() {
    local name="${1:-}" pattern="$2"
    [ -n "$name" ] || die "Nama file backup belum diisi (lihat: list)"
    [[ "$name" == */* ]] && die "Pakai nama file saja, tanpa folder: ${name}"
    # shellcheck disable=SC2053 # pola glob disengaja
    [[ "$name" == $pattern ]] || die "Nama file harus berpola ${pattern}: ${name}"
    [ -f "${BACKUP_DIR}/${name}" ] || die "File tidak ditemukan: ${BACKUP_DIR}/${name}"
    gzip -t "${BACKUP_DIR}/${name}" 2>/dev/null || die "Arsip rusak atau tidak lengkap: ${name}"
    echo "${BACKUP_DIR}/${name}"
}

restore() {
    local file tables
    file=$(backup_file "${1:-}" 'db-*.sql.gz') || exit 1

    # Kondisi saat ini disimpan dulu, supaya restore yang salah pilih file bisa dibatalkan
    mkdir -p "$BACKUP_DIR"
    backup_database "$(date -u +%Y%m%d-%H%M%S)-pre-restore" || die "Restore dibatalkan: backup kondisi saat ini gagal"

    # Semua tabel dihapus dulu: tabel dari migration setelah waktu backup tidak boleh tersisa
    tables=$(mysql "${MYSQL_ARGS[@]}" -N -B -e \
        "SET SESSION group_concat_max_len = 1000000; SELECT GROUP_CONCAT(CONCAT('\`', table_name, '\`')) FROM information_schema.tables WHERE table_schema = DATABASE()" "$DB_NAME") \
        || die "GAGAL: tidak bisa membaca daftar tabel"
    if [ -n "$tables" ] && [ "$tables" != "NULL" ]; then
        mysql "${MYSQL_ARGS[@]}" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE ${tables}; SET FOREIGN_KEY_CHECKS=1;" "$DB_NAME" \
            || die "GAGAL: tidak bisa mengosongkan database"
    fi

    log "memulihkan database ${DB_NAME} dari $(basename "$file")"
    if ! gunzip -c "$file" | mysql "${MYSQL_ARGS[@]}" "$DB_NAME"; then
        die "GAGAL: restore tidak selesai. Kondisi sebelum restore tersimpan di file *-pre-restore.sql.gz"
    fi
    log "restore selesai"
}

case "${1:-schedule}" in
    schedule) schedule ;;
    run) backup_and_record ;;
    restore) restore "${2:-}" ;;
    stream)
        file=$(backup_file "${2:-}" 'storage-*.tar.gz') || exit 1
        cat "$file"
        ;;
    list) ls -lh "$BACKUP_DIR" ;;
    *) die "Perintah tidak dikenal: $1 (schedule | run | restore FILE | stream FILE | list)" ;;
esac
