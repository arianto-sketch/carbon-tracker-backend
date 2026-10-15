#!/usr/bin/env bash
# Uji asap stack Docker yang sudah berjalan (`make setup`), seperti pemeriksaan setelah deploy:
# admin + data master, nginx (SPA, header, cache aset), API lewat nginx, queue worker (laporan),
# backup (retensi) dan restore. Butuh curl dan jq. Dipakai workflow .github/workflows/docker.yml.
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
echo "$ADMIN_PASSWORD" | docker compose exec -T app php artisan app:create-admin "$ADMIN_EMAIL" --name="CI Admin" --password-stdin
make seed-master

step "nginx: health check, SPA, header keamanan, cache aset"
curl -fsS "${BASE}/up" > /dev/null
curl -fsS "${BASE}/" | grep -q 'id="app"' || fail "index.html SPA tidak tersaji"
curl -fsS "${BASE}/reports/summary" | grep -q 'id="app"' || fail "rute SPA tidak jatuh ke index.html"
curl -fsSI "${BASE}/" | grep -qi '^x-frame-options: DENY' || fail "header X-Frame-Options tidak ada"
asset=$(curl -fsS "${BASE}/" | grep -o '/assets/[^"]*\.js' | head -n 1)
curl -fsSI "${BASE}${asset}" | grep -qi '^cache-control: max-age=31536000' || fail "aset ${asset} tidak di-cache lama"

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
gzip -t "$db_file"
zcat "$db_file" | grep -q 'CREATE TABLE `users`' || fail "dump tidak berisi tabel users"
tar -tzf "$storage_file" > /dev/null
[ ! -e "${BACKUP_PATH}/db-20000101-000000.sql.gz" ] || fail "backup lebih dari 14 hari tidak dihapus"

step "Restore: data setelah backup hilang, data sebelum backup kembali"
api POST users '{"name":"Setelah Backup","email":"setelah-backup@example.test","password":"rahasia123","password_confirmation":"rahasia123","role":"viewer"}' > /dev/null
make restore FILE="$(basename "$db_file")" CONFIRM=YA
TOKEN=$(login)
[ "$(api GET 'users?search=setelah-backup' | jq '.meta.total')" = "0" ] || fail "user yang dibuat setelah backup masih ada"
make restore-storage FILE="$(basename "$storage_file")"

step "Semua pemeriksaan lolos"
