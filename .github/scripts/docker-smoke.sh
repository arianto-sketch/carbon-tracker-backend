#!/usr/bin/env bash
# Uji asap stack Docker yang sudah berjalan (`make setup`), seperti pemeriksaan setelah deploy:
# admin + data master, nginx (SPA, header, cache aset), API lewat nginx, queue worker (laporan),
# backup (retensi), pemeriksaan status backup, off-site terenkripsi ke S3 lokal (overlay
# .github/compose.offsite-ci.yml), dan restore. Butuh curl dan jq. Dipakai workflow .github/workflows/docker.yml.
set -euo pipefail

BASE="${BASE_URL:-http://localhost:8080}"
API="${BASE}/api/v1"
BACKUP_PATH="${BACKUP_PATH:-./backups}"
ADMIN_EMAIL=ci-admin@example.test
ADMIN_PASSWORD='Rahasia-CI-Panjang-2026'

step() { echo; echo "== $*"; }
fail() { echo "GAGAL: $*" >&2; exit 1; }

api() {
    # api METHOD PATH [JSON]
    local args=(-fsS -X "$1" "${API}/$2" -H 'Accept: application/json' -H 'Content-Type: application/json'
        -H "Authorization: Bearer ${TOKEN:-}")
    if [ $# -ge 3 ]; then
        args+=(-d "$3")
    fi
    curl "${args[@]}"
}

login() {
    curl -fsS -X POST "${API}/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASSWORD}\"}" | jq -r '.data.token'
}

step "Admin pertama dan data master"
# Tanpa terminal, make create-admin membaca password dari stdin
echo "$ADMIN_PASSWORD" | make create-admin EMAIL="$ADMIN_EMAIL" NAME="CI Admin"
make seed-master

step "nginx: health check, SPA, header keamanan, cache aset"
curl -fsS "${BASE}/up" > /dev/null
curl -fsS "${BASE}/" | grep -q 'id="app"' || fail "index.html SPA tidak tersaji"
curl -fsS "${BASE}/reports/summary" | grep -q 'id="app"' || fail "rute SPA tidak jatuh ke index.html"
curl -fsSI "${BASE}/" | grep -qi '^x-frame-options: DENY' || fail "header X-Frame-Options tidak ada"
asset=$(curl -fsS "${BASE}/" | grep -o '/assets/[^"]*\.js' | head -n 1)
curl -fsSI "${BASE}${asset}" | grep -qi '^cache-control: max-age=31536000' || fail "aset ${asset} tidak di-cache lama"
curl -fsS -o /dev/null -D - -H 'Accept-Encoding: gzip' "${BASE}${asset}" | grep -qi '^content-encoding: gzip' || fail "aset JS tidak dikompres gzip"

step "API lewat nginx: login, profil, faktor emisi"
TOKEN=$(login)
[ -n "$TOKEN" ] && [ "$TOKEN" != "null" ] || fail "login admin gagal"
[ "$(api GET auth/me | jq -r '.data.email')" = "$ADMIN_EMAIL" ] || fail "/auth/me tidak mengembalikan admin"
factors=$(api GET 'emission-factors?per_page=50' | jq '.meta.total')
[ "$factors" -ge 8 ] || fail "faktor emisi bawaan belum terisi (${factors})"

step "Queue worker: laporan Excel dibuat di background"
job=$(api POST reports/generate "{\"period_year\": $(date +%Y), \"format\": \"xlsx\"}" | jq -r '.data.job_id')
status=pending
for _ in $(seq 1 30); do
    status=$(api GET "reports/jobs/${job}" | jq -r '.data.status')
    [ "$status" = "done" ] || [ "$status" = "failed" ] && break
    sleep 2
done
[ "$status" = "done" ] || fail "job laporan ${job} berstatus ${status}"
size=$(curl -fsS "${API}/reports/download/${job}" -H "Authorization: Bearer ${TOKEN}" | wc -c)
[ "$size" -gt 0 ] || fail "file laporan kosong"

step "Backup: database + lampiran, backup lama dihapus"
sudo touch "${BACKUP_PATH}/db-20000101-000000.sql.gz"
make backup
db_file=$(ls -t "${BACKUP_PATH}"/db-*.sql.gz | head -n 1)
storage_file=$(ls -t "${BACKUP_PATH}"/storage-*.tar.gz | head -n 1)
# Berisi data pribadi: hanya root yang boleh membaca
[ "$(sudo stat -c %a "$db_file")" = "600" ] || fail "file backup bisa dibaca user lain"
sudo gzip -t "$db_file"
sudo zcat "$db_file" | grep -q 'CREATE TABLE `users`' || fail "dump tidak berisi tabel users"
sudo tar -tzf "$storage_file" > /dev/null
[ ! -e "${BACKUP_PATH}/db-20000101-000000.sql.gz" ] || fail "backup lebih dari 14 hari tidak dihapus"

step "Pemeriksaan backup (scheduler): status ditulis service backup, off-site masih nonaktif"
check=$(docker compose exec -T scheduler php artisan backups:check) || fail "backups:check melaporkan masalah: ${check}"
echo "$check"
grep -q 'off-site nonaktif' <<< "$check" || fail "backups:check tidak membaca status backup dan off-site"

step "Off-site: backup dienkripsi (age) ke S3, masa simpan diterapkan, bisa diunduh dan didekripsi"
key_file=$(mktemp)
make offsite-keygen > "$key_file"
recipient=$(sed -n 's/^# public key: //p' "$key_file")
[[ "$recipient" == age1* ]] || fail "make offsite-keygen tidak menghasilkan kunci publik age"
# S3 lokal (rclone serve s3) dari overlay CI; langkah lain sama seperti di server:
# pengaturan bucket di .env, kunci akses di secrets/offsite.env (tidak terbaca container app)
cat >> .env <<EOF
COMPOSE_FILE=compose.yml:.github/compose.offsite-ci.yml
OFFSITE_AGE_RECIPIENT=${recipient}
OFFSITE_S3_PROVIDER=Other
OFFSITE_S3_ENDPOINT=http://s3:9000
OFFSITE_S3_REGION=us-east-1
OFFSITE_S3_BUCKET=ci-backups
EOF
make offsite-credentials
[ "$(stat -c %a secrets/offsite.env)" = "600" ] || fail "secrets/offsite.env bisa dibaca user lain"
sed -i -e 's|^RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID=.*|RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID=ci-access-key|' \
    -e 's|^RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY=.*|RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY=ci-secret-key|' secrets/offsite.env
# Hanya S3 yang dinyalakan: sinkron dijalankan lewat make di bawah, tidak berbarengan dengan
# service offsite yang terjadwal (di server cukup: docker compose up -d offsite)
docker compose up -d s3
remote=offsite:ci-backups/carbon-tracker
rclone_ci() { docker compose run --rm -T --no-deps --entrypoint rclone offsite "$@"; }
s3_ready=no
for _ in $(seq 1 15); do
    rclone_ci lsf "offsite:ci-backups" > /dev/null 2>&1 && { s3_ready=yes; break; }
    sleep 2
done
[ "$s3_ready" = yes ] || fail "S3 lokal tidak siap atau kredensial offsite tidak terbaca"
# Kunci akses bucket hanya untuk service offsite, tidak sampai ke container app
app_env=$(docker compose exec -T app printenv)
! grep -q 'RCLONE_CONFIG_OFFSITE\|ci-secret-key' <<< "$app_env" || fail "kunci akses bucket off-site terbaca di container app"
# Sudah ada di remote: backup sangat lama (dihapus), backup pertama dan pertengahan bulan ~40 hari lalu
# (yang pertama disimpan sebagai bulanan, yang lain dihapus), dan file lain (tidak boleh disentuh)
month=$(date -u -d '-40 days' +%Y%m)
for name in db-20000101-000000.sql.gz.age "db-${month}01-000000.sql.gz.age" "db-${month}15-000000.sql.gz.age" catatan.txt; do
    echo lama | rclone_ci rcat "${remote}/${name}"
done
make offsite-sync
listing=$(make offsite-list)
echo "$listing"
for name in "$(basename "$db_file").age" "$(basename "$storage_file").age" "db-${month}01-000000.sql.gz.age" catatan.txt; do
    grep -q "$name" <<< "$listing" || fail "${name} tidak ada di off-site"
done
for name in db-20000101-000000.sql.gz.age "db-${month}15-000000.sql.gz.age"; do
    ! grep -q "$name" <<< "$listing" || fail "${name} seharusnya dihapus (lewat masa simpan)"
done
! grep -q 'pre-restore' <<< "$listing" || fail "backup pre-restore ikut di-upload"
# Isi di off-site terenkripsi, bukan gzip yang bisa langsung dibaca
[ "$(rclone_ci cat --head 21 "${remote}/$(basename "$db_file").age")" = "age-encryption.org/v1" ] \
    || fail "file di off-site tidak terenkripsi age"
# Unduh + dekripsi dengan kunci privat: hasilnya identik dengan backup asli
sudo mv "$db_file" "${db_file}.asli"
make offsite-fetch FILE="$(basename "$db_file")" IDENTITY="$key_file"
sudo cmp "$db_file" "${db_file}.asli" || fail "hasil unduh off-site berbeda dengan backup asli"
sudo rm "${db_file}.asli"
rm -f "$key_file"
check=$(docker compose exec -T scheduler php artisan backups:check) || fail "backups:check melaporkan masalah: ${check}"
echo "$check"
grep -q 'off-site terakhir' <<< "$check" || fail "backups:check tidak membaca status off-site"

step "Restore: data setelah backup hilang, data sebelum backup kembali"
db_user=$(sed -n 's/^DB_USERNAME=//p' .env); db_pass=$(sed -n 's/^DB_PASSWORD=//p' .env); db_name=$(sed -n 's/^DB_DATABASE=//p' .env)
docker compose exec -T -e MYSQL_PWD="$db_pass" mysql mysql -u "$db_user" "$db_name" -e 'CREATE TABLE dibuat_setelah_backup (id INT)'
api POST users '{"name":"Setelah Backup","email":"setelah-backup@example.test","password":"rahasia123","password_confirmation":"rahasia123","role":"viewer"}' > /dev/null
make restore FILE="$(basename "$db_file")" CONFIRM=YA
TOKEN=$(login)
[ "$(api GET 'users?search=setelah-backup' | jq '.meta.total')" = "0" ] || fail "user yang dibuat setelah backup masih ada"
tables=$(docker compose exec -T -e MYSQL_PWD="$db_pass" mysql mysql -u "$db_user" "$db_name" -N -e "SHOW TABLES LIKE 'dibuat_setelah_backup'")
[ -z "$tables" ] || fail "tabel yang dibuat setelah backup masih ada"
ls "${BACKUP_PATH}"/db-*-pre-restore.sql.gz > /dev/null || fail "kondisi sebelum restore tidak di-backup"
make restore-storage FILE="$(basename "$storage_file")"

step "Semua pemeriksaan lolos"
