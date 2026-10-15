#!/usr/bin/env bash
# Backup harian database (mysqldump) dan lampiran, dengan masa simpan BACKUP_RETENTION_DAYS hari.
# Dijalankan di service "backup" (image mysql, jadi versi mysqldump sama dengan server).
#
#   schedule        jalan terus: backup tiap hari pukul BACKUP_TIME_UTC (default)
#   run             backup sekarang, lalu hapus backup yang sudah lewat masa simpan
#   restore FILE    pulihkan database dari FILE (db-*.sql.gz di /backups)
#   list            daftar file backup
set -uo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
STORAGE_DIR="${STORAGE_DIR:-/storage}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_TIME_UTC="${BACKUP_TIME_UTC:-19:00}"
DB_NAME="${DB_DATABASE:?DB_DATABASE belum diisi}"
# Lewat env, bukan argumen: password tidak terlihat di daftar proses
export MYSQL_PWD="${DB_PASSWORD:?DB_PASSWORD belum diisi}"
MYSQL_ARGS=(-h "${DB_HOST:-mysql}" -u "${DB_USERNAME:?DB_USERNAME belum diisi}")

log() { echo "[backup] $(date -u '+%Y-%m-%d %H:%M:%S') UTC $*"; }

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
    local file="${BACKUP_DIR}/storage-$1.tar.gz"
    if [ ! -d "$STORAGE_DIR" ]; then
        log "lampiran dilewati: ${STORAGE_DIR} tidak di-mount"
        return 0
    fi
    if ! tar -czf "${file}.tmp" -C "$STORAGE_DIR" .; then
        rm -f "${file}.tmp"
        log "GAGAL: arsip lampiran"
        return 1
    fi
    mv "${file}.tmp" "$file"
    log "lampiran -> $(basename "$file") ($(du -h "$file" | cut -f1))"
}

# Simpan backup RETENTION_DAYS hari terakhir (termasuk hari ini); tanggal dibaca dari nama file
prune() {
    local cutoff name day f
    cutoff=$(date -u -d "-$((RETENTION_DAYS - 1)) days" +%Y%m%d)
    shopt -s nullglob
    for f in "$BACKUP_DIR"/db-*.sql.gz "$BACKUP_DIR"/storage-*.tar.gz; do
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
    backup_database "$stamp" || status=1
    backup_storage "$stamp" || status=1
    # Backup lama hanya dihapus bila backup hari ini berhasil
    if [ "$status" -eq 0 ]; then
        prune
    fi
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
        run_backup || log "GAGAL: backup tidak lengkap, dicoba lagi pada jadwal berikutnya"
    done
}

restore() {
    local file="${1:-}"
    if [ -z "$file" ]; then
        echo "Pakai: restore <db-YYYYmmdd-HHMMSS.sql.gz>" >&2
        exit 1
    fi
    [[ "$file" == /* ]] || file="${BACKUP_DIR}/${file}"
    if [ ! -f "$file" ]; then
        echo "File tidak ditemukan: ${file}" >&2
        exit 1
    fi
    log "memulihkan database ${DB_NAME} dari $(basename "$file")"
    if ! gunzip -c "$file" | mysql "${MYSQL_ARGS[@]}" "$DB_NAME"; then
        log "GAGAL: restore tidak selesai"
        exit 1
    fi
    log "restore selesai"
}

case "${1:-schedule}" in
    schedule) schedule ;;
    run) run_backup ;;
    restore) restore "${2:-}" ;;
    list) ls -lh "$BACKUP_DIR" ;;
    *)
        echo "Perintah tidak dikenal: $1 (schedule | run | restore FILE | list)" >&2
        exit 1
        ;;
esac
