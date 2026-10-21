#!/usr/bin/env bash
# Salinan off-site backup harian: setiap file backup dienkripsi dengan age (kunci PUBLIK) lalu
# di-upload ke object storage S3-compatible lewat rclone. Kunci privat tidak pernah ada di server;
# ia hanya dibutuhkan saat restore (make offsite-fetch). Dijalankan di service "offsite".
#
#   schedule        jalan terus: sinkron tiap OFFSITE_INTERVAL_MINUTES menit (default)
#   sync            upload backup yang belum ada di remote, lalu terapkan masa simpan remote
#   list            daftar file di remote
#   fetch FILE      unduh FILE (db-*.sql.gz / storage-*.tar.gz) dan dekripsi ke folder backup;
#                   kunci privat age di-mount ke /run/age-identity oleh make offsite-fetch
#   keygen          buat pasangan kunci age baru (kunci privat dicetak sekali: simpan offline)
#
# Masa simpan remote: OFFSITE_KEEP_DAILY hari terakhir, ditambah backup pertama setiap bulan selama
# OFFSITE_KEEP_MONTHLY bulan. Dihitung dari backup TERBARU (bukan hari ini), jadi bila backup berhenti
# salinan lama tidak ikut habis. File lain di bucket (yang bukan backup aplikasi ini) tidak disentuh.
set -uo pipefail
umask 077

BACKUP_DIR="${BACKUP_DIR:-/backups}"
RESTORE_DIR="${RESTORE_DIR:-/restore}"
STATUS_DIR="${STATUS_DIR:-/status}"
IDENTITY_FILE="${IDENTITY_FILE:-/run/age-identity}"
RECIPIENT="${OFFSITE_AGE_RECIPIENT:-}"
BUCKET="${OFFSITE_S3_BUCKET:-}"
PREFIX="${OFFSITE_PREFIX:-carbon-tracker}"
INTERVAL_MINUTES="${OFFSITE_INTERVAL_MINUTES:-30}"
KEEP_DAILY="${OFFSITE_KEEP_DAILY:-14}"
KEEP_MONTHLY="${OFFSITE_KEEP_MONTHLY:-12}"
# Remote "offsite" dikonfigurasi lewat env RCLONE_CONFIG_OFFSITE_* (compose.yml)
REMOTE="offsite:${BUCKET}${PREFIX:+/${PREFIX}}"

# Hanya backup harian aplikasi ini yang dikelola (bukan *-pre-restore, bukan file lain di bucket)
DB_PATTERN='^db-[0-9]{8}-[0-9]{6}\.sql\.gz$'
STORAGE_PATTERN='^storage-[0-9]{8}-[0-9]{6}\.tar\.gz$'

WORK_DIR=$(mktemp -d)
trap 'rm -rf "$WORK_DIR"' EXIT

log() { echo "[offsite] $(date -u '+%Y-%m-%d %H:%M:%S') UTC $*"; }
die() { echo "$*" >&2; exit 1; }

# Status untuk `php artisan backups:check` (peringatan email). $1 = ok | failed | disabled, $2 = pesan
write_status() {
    [ -d "$STATUS_DIR" ] || return 0
    local file="${STATUS_DIR}/offsite.json" now last_success enabled=true error=null message
    now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    last_success=$(sed -n 's/.*"last_success_at": *"\([^"]*\)".*/\1/p' "$file" 2>/dev/null)
    case "$1" in
        ok) last_success=$now ;;
        disabled) enabled=false ;;
    esac
    if [ "$1" = failed ]; then
        # Pesan bisa memuat nilai env (mis. nama remote): karakter kontrol, kutip, dan backslash dibuang agar JSON valid
        message=$(printf '%s' "${2:-}" | tr -d '\000-\037"\\')
        error="\"${message}\""
    fi
    # File sementara unik: jadwal dan `make offsite-sync` bisa menulis status bersamaan
    local tmp
    tmp=$(mktemp "${file}.XXXXXX") || return 1
    printf '{"enabled": %s, "last_attempt_at": "%s", "last_success_at": %s, "last_error": %s}\n' \
        "$enabled" "$now" "$( [ -n "$last_success" ] && echo "\"${last_success}\"" || echo null )" "$error" \
        > "$tmp" && chmod 644 "$tmp" && mv "$tmp" "$file"
}

# Kosong semua = nonaktif (return 1); terisi sebagian atau salah format = konfigurasi salah (exit)
check_config() {
    if [ -z "$BUCKET" ] && [ -z "$RECIPIENT" ]; then
        return 1
    fi
    local problem=""
    [ -n "$BUCKET" ] || problem="OFFSITE_S3_BUCKET belum diisi"
    [ -n "$RECIPIENT" ] || problem="OFFSITE_AGE_RECIPIENT belum diisi (kunci publik dari make offsite-keygen)"
    # Kunci X25519 dari age-keygen (age1 + karakter bech32); jenis kunci age lain tidak didukung
    if [ -n "$RECIPIENT" ] && ! [[ "$RECIPIENT" =~ ^age1[02-9ac-hj-np-z]{20,}$ ]]; then
        problem="OFFSITE_AGE_RECIPIENT bukan kunci publik age (harus diawali age1)"
    fi
    for n in "$INTERVAL_MINUTES" "$KEEP_DAILY" "$KEEP_MONTHLY"; do
        # Tanpa nol di depan: "08" akan dibaca bash sebagai bilangan oktal yang tidak valid
        if ! [[ "$n" =~ ^[1-9][0-9]*$ ]]; then
            problem="OFFSITE_INTERVAL_MINUTES, OFFSITE_KEEP_DAILY, dan OFFSITE_KEEP_MONTHLY harus bilangan bulat >= 1"
        fi
    done
    if [ -n "$problem" ]; then
        echo "$problem"
        return 2
    fi
    return 0
}

stamp_day() { local rest="${1#*-}"; echo "${rest:0:8}"; }

# Nama yang disimpan dari daftar nama satu jenis (urut naik), sesuai masa simpan di atas
retained() {
    (( $# )) || return 0
    local names=("$@") newest day_cutoff month_cutoff name day month last_month=""
    newest=$(stamp_day "${names[-1]}")
    day_cutoff=$(date -u -d "${newest:0:4}-${newest:4:2}-${newest:6:2} -$((KEEP_DAILY - 1)) days" +%Y%m%d)
    month_cutoff=$(date -u -d "${newest:0:4}-${newest:4:2}-01 -$((KEEP_MONTHLY - 1)) months" +%Y%m)
    for name in "${names[@]}"; do
        day=$(stamp_day "$name")
        month=${day:0:6}
        if [[ ! "$day" < "$day_cutoff" ]]; then
            echo "$name"
        elif [[ "$month" != "$last_month" && ! "$month" < "$month_cutoff" ]]; then
            echo "$name"
        fi
        last_month=$month
    done
}

# Daftar file di remote (nama tanpa folder); folder yang belum ada = kosong
remote_files() {
    local out code
    out=$(rclone lsf --files-only "$REMOTE" 2>&1)
    code=$?
    if (( code == 3 )); then
        return 0
    elif (( code != 0 )); then
        echo "$out" >&2
        return 1
    fi
    printf '%s\n' "$out"
}

upload() {
    local name="$1"
    if ! age -r "$RECIPIENT" -o "${WORK_DIR}/${name}.age" "${BACKUP_DIR}/${name}"; then
        log "GAGAL: enkripsi ${name}"
        return 1
    fi
    # copyto memverifikasi ukuran dan checksum setelah upload
    if ! rclone copyto "${WORK_DIR}/${name}.age" "${REMOTE}/${name}.age"; then
        rm -f "${WORK_DIR}/${name}.age"
        log "GAGAL: upload ${name}.age"
        return 1
    fi
    rm -f "${WORK_DIR}/${name}.age"
    log "upload ${name}.age"
}

# $1 = pola nama; mengisi variabel global UPLOADED, DELETED, FAILED
sync_kind() {
    local pattern="$1" name all keep
    local -A on_remote=() kept=()
    while IFS= read -r name; do
        [[ "$name" == *.age ]] && on_remote["${name%.age}"]=1
    done <<< "$REMOTE_LIST"

    mapfile -t all < <( { printf '%s\n' "${!on_remote[@]}"; ls -1 "$BACKUP_DIR" 2>/dev/null; } | grep -E "$pattern" | sort -u)
    mapfile -t keep < <(retained "${all[@]}")

    for name in "${keep[@]}"; do
        kept["$name"]=1
        if [ -z "${on_remote[$name]:-}" ] && [ -f "${BACKUP_DIR}/${name}" ]; then
            if upload "$name"; then UPLOADED=$((UPLOADED + 1)); else FAILED=1; fi
        fi
    done

    # Salinan lama dihapus hanya bila semua upload berhasil
    (( FAILED )) && return 0
    for name in "${!on_remote[@]}"; do
        [[ "$name" =~ $pattern ]] || continue
        if [ -z "${kept[$name]:-}" ]; then
            if rclone deletefile "${REMOTE}/${name}.age"; then
                log "hapus ${name}.age (lewat masa simpan)"
                DELETED=$((DELETED + 1))
            else
                FAILED=1
            fi
        fi
    done
}

# Satu sinkron dalam satu waktu (jadwal vs `make offsite-sync`): tanpa ini keduanya bisa menghapus file
# yang sama dan salah satunya tercatat gagal. Kunci di volume status yang dipakai bersama; dilepas
# begitu subshell selesai (tidak ditahan selama jeda antar-sinkron).
sync_locked() {
    if [ -d "$STATUS_DIR" ] && command -v flock > /dev/null; then
        ( flock 9 && sync_once ) 9> "${STATUS_DIR}/offsite.lock"
    else
        sync_once
    fi
}

sync_once() {
    local problem
    problem=$(check_config)
    case $? in
        1) log "nonaktif: isi OFFSITE_S3_BUCKET dan OFFSITE_AGE_RECIPIENT untuk mengaktifkan"; write_status disabled; return 0 ;;
        2) log "GAGAL: ${problem}"; write_status failed "$problem"; return 1 ;;
    esac

    if ! REMOTE_LIST=$(remote_files); then
        log "GAGAL: tidak bisa membaca daftar file di ${REMOTE}"
        write_status failed "tidak bisa membaca daftar file di ${REMOTE} (cek kredensial, endpoint, dan bucket)"
        return 1
    fi

    UPLOADED=0 DELETED=0 FAILED=0
    sync_kind "$DB_PATTERN"
    sync_kind "$STORAGE_PATTERN"

    if (( FAILED )); then
        write_status failed "sebagian file gagal di-upload atau dihapus di ${REMOTE} (lihat: make logs SERVICE=offsite)"
        return 1
    fi
    (( UPLOADED + DELETED )) && log "selesai: ${UPLOADED} di-upload, ${DELETED} dihapus"
    write_status ok
}

schedule() {
    # Berhenti segera saat container dihentikan (sleep dijalankan di background lalu ditunggu)
    trap 'log "berhenti"; exit 0' TERM INT
    local problem
    problem=$(check_config)
    case $? in
        1)
            log "nonaktif: isi OFFSITE_S3_BUCKET dan OFFSITE_AGE_RECIPIENT di .env, lalu: docker compose up -d offsite"
            write_status disabled
            sleep infinity &
            wait $!
            ;;
        2)
            # Tidak exit: container tidak restart berulang; backups:check melaporkan lewat email
            log "GAGAL: ${problem}"
            write_status failed "$problem"
            sleep infinity &
            wait $!
            ;;
    esac
    log "sinkron ke ${REMOTE} tiap ${INTERVAL_MINUTES} menit; simpan ${KEEP_DAILY} harian + ${KEEP_MONTHLY} bulanan"
    while true; do
        sync_locked || log "GAGAL: sinkron tidak lengkap, dicoba lagi dalam ${INTERVAL_MINUTES} menit"
        sleep "$((INTERVAL_MINUTES * 60))" &
        wait $!
    done
}

fetch() {
    local name="${1:-}"
    [ -n "$name" ] || die "Nama file belum diisi (lihat: make offsite-list), mis. db-20261020-190000.sql.gz"
    [[ "$name" =~ $DB_PATTERN || "$name" =~ $STORAGE_PATTERN ]] \
        || die "Nama file harus db-YYYYmmdd-HHMMSS.sql.gz atau storage-YYYYmmdd-HHMMSS.tar.gz (tanpa .age): ${name}"
    [ -n "$BUCKET" ] || die "OFFSITE_S3_BUCKET belum diisi"
    [ -s "$IDENTITY_FILE" ] || die "Kunci privat age tidak ditemukan (make offsite-fetch FILE=... IDENTITY=<file kunci privat>)"
    [ -d "$RESTORE_DIR" ] || die "Folder tujuan ${RESTORE_DIR} tidak di-mount"
    [ ! -e "${RESTORE_DIR}/${name}" ] || die "Sudah ada di folder backup: ${name}. Langsung restore, atau hapus dulu file itu."

    log "unduh ${name}.age dari ${REMOTE}"
    rclone copyto "${REMOTE}/${name}.age" "${WORK_DIR}/${name}.age" || die "GAGAL: unduh ${name}.age"
    if ! age -d -i "$IDENTITY_FILE" -o "${RESTORE_DIR}/${name}.tmp" "${WORK_DIR}/${name}.age"; then
        rm -f "${RESTORE_DIR}/${name}.tmp"
        die "GAGAL: dekripsi ${name}.age (kunci privat tidak cocok dengan kunci publik saat upload?)"
    fi
    if ! gzip -t "${RESTORE_DIR}/${name}.tmp" 2>/dev/null; then
        rm -f "${RESTORE_DIR}/${name}.tmp"
        die "GAGAL: hasil dekripsi ${name} bukan arsip gzip yang utuh"
    fi
    mv "${RESTORE_DIR}/${name}.tmp" "${RESTORE_DIR}/${name}"
    log "siap dipulihkan: ${name}"
}

keygen() {
    {
        echo "Kunci age baru untuk backup off-site."
        echo "1. Simpan SELURUH output di bawah (termasuk baris AGE-SECRET-KEY-...) di tempat aman DI LUAR server,"
        echo "   mis. password manager. Tanpa kunci privat ini, backup off-site tidak bisa dipulihkan."
        echo "2. Isi OFFSITE_AGE_RECIPIENT di .env server dengan kunci publik (age1...), lalu: docker compose up -d offsite"
        echo
    } >&2
    age-keygen
}

case "${1:-schedule}" in
    schedule) schedule ;;
    sync)
        sync_locked
        ;;
    list)
        [ -n "$BUCKET" ] || die "OFFSITE_S3_BUCKET belum diisi"
        rclone lsl "$REMOTE"
        code=$?
        # 3 = folder belum ada (belum pernah sinkron)
        if (( code == 3 )); then echo "(kosong)"; elif (( code != 0 )); then exit "$code"; fi
        ;;
    fetch) fetch "${2:-}" ;;
    keygen) keygen ;;
    *) die "Perintah tidak dikenal: $1 (schedule | sync | list | fetch FILE | keygen)" ;;
esac
