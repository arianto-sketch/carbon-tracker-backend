# Deploy ke VPS (Docker)

Panduan menjalankan Carbon Tracker di satu server Linux dengan Docker Compose. Semua perintah dijalankan dari folder repo backend di server. Ketik `make` untuk melihat daftar perintah.

## Arsitektur

```
Internet ──► caddy :443 (opsional, HTTPS otomatis) ──► web :8080 (nginx)
                                                         ├─ /            SPA (build repo frontend)
                                                         └─ /api, /up ─► app (php-fpm) ──► mysql
                                                    queue (laporan, email) ┘     ▲
                                                    scheduler (tugas harian) ────┤
                                                    backup (mysqldump harian) ───┘
```

- SPA dan API berada di satu domain, jadi tidak perlu CORS.
- MySQL tidak dibuka ke luar. Hanya service di jaringan Docker internal yang bisa terhubung.
- Data persisten ada di volume `mysql_data` (database) dan `app_storage` (lampiran entri dan file laporan), ditambah folder `./backups` di host.

| Service | Fungsi |
| --- | --- |
| `app` | Laravel API (php-fpm) |
| `web` | nginx: menyajikan SPA dan meneruskan `/api` ke `app` |
| `queue` | Worker antrean: generate laporan Excel dan kirim email notifikasi approval |
| `scheduler` | Tugas terjadwal: hapus token kedaluwarsa, purge lampiran entri terhapus |
| `mysql` | Database MySQL 8.4 |
| `backup` | Backup harian database dan lampiran, simpan 14 hari |
| `caddy` | Hanya dengan `compose.tls.yml`: sertifikat Let's Encrypt dan HTTPS |

## Kebutuhan server

- Linux 64-bit, mis. Ubuntu 22.04/24.04 LTS. Perkiraan awal 2 vCPU, 2 GB RAM, dan 20 GB disk. Sesuaikan dengan jumlah user dan lampiran.
- Docker Engine 24 atau lebih baru, dengan plugin Docker Compose 2.24 atau lebih baru. Cek dengan `docker compose version`.
- `git`, `make`, dan `openssl`, mis. `sudo apt install git make openssl`.
- Domain yang record DNS-nya mengarah ke IP server. Ini wajib untuk HTTPS.
- Akun SMTP untuk email notifikasi.
- Firewall hanya membuka port 22, 80, dan 443, mis. `ufw allow OpenSSH && ufw allow 80,443/tcp && ufw enable`.

## Instalasi pertama

```bash
git clone https://github.com/arianto-sketch/carbon-tracker-backend.git carbon-tracker
cd carbon-tracker
make frontend          # clone repo frontend ke ./carbon-tracker-frontend
make .env              # membuat .env dari .env.production.example (chmod 600)
make key               # salin hasilnya ke APP_KEY di .env
```

Lalu buka `.env` dan isi semua nilai `<...>`:

- `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`: `https://domain-anda`
- `DB_PASSWORD` dan `DB_ROOT_PASSWORD`: password acak yang panjang, mis. hasil `openssl rand -base64 24`. `DB_USERNAME` tidak boleh `root`.
- `MAIL_*`: kredensial SMTP
- HTTPS: isi `DOMAIN` dan `ACME_EMAIL`, lalu aktifkan baris `COMPOSE_FILE=compose.yml:compose.tls.yml`. Tanpa baris itu, aplikasi berjalan di HTTP port `HTTP_PORT`. Itu cocok bila TLS diurus di luar Docker, mis. load balancer atau Cloudflare.

Setelah itu jalankan:

```bash
make setup                                                # build image, migrate, start semua service
make create-admin EMAIL=admin@domain-anda NAME="Admin"   # password diketik, minimal 12 karakter
make seed-master                                         # kategori dan faktor emisi bawaan (butuh admin)
make ps                                                  # semua service harus "healthy"/"running"
```

Buka `https://domain-anda`, login sebagai admin, lalu tambahkan user lain di menu **Users**. Tinjau juga scope GHG setiap faktor emisi di menu **Emission Factors**.

> Jangan menjalankan `php artisan db:seed` (DatabaseSeeder) di production. Seeder itu membuat akun contoh berpassword `password`. `make seed-master` hanya mengisi kategori dan faktor emisi.

## Rilis versi baru

```bash
make deploy
```

Perintah ini menjalankan `git pull` untuk backend dan frontend, build image, menjalankan migration dengan image baru, lalu mengganti container. Worker antrean diberi waktu 60 detik untuk menyelesaikan job yang sedang berjalan.

- **Memakai tag:** isi `APP_VERSION` di `.env` dengan versi rilis (mis. `v1.4.0`) sebelum `make deploy`. Image lama tetap tersimpan dengan tag versinya.
- **Rollback:**
  1. Checkout versi sebelumnya (`git checkout v1.3.0`, begitu juga di folder frontend bila perlu).
  2. Kembalikan `APP_VERSION`.
  3. Jalankan `make build up`.
  4. Bila rilis yang dibatalkan berisi migration yang mengubah data, pulihkan database dari backup sebelum rilis (lihat bagian berikut).
  - Dianjurkan menjalankan `make backup` tepat sebelum `make deploy`.

## Backup dan restore

Service `backup` berjalan terus:

- Setiap hari pukul `BACKUP_TIME_UTC` (default 19:00 UTC = 02:00 WIB) ia membuat:
  - `backups/db-YYYYmmdd-HHMMSS.sql.gz`: `mysqldump --single-transaction` seluruh database aplikasi
  - `backups/storage-YYYYmmdd-HHMMSS.tar.gz`: semua lampiran entri dan file laporan
- Backup yang lebih lama dari `BACKUP_RETENTION_DAYS` (default 14) hari dihapus otomatis. Penghapusan hanya terjadi bila backup hari itu berhasil.
- Log backup bisa dilihat dengan `make logs SERVICE=backup`.

| Perintah | Fungsi |
| --- | --- |
| `make backup` | Backup sekarang |
| `make backups` | Daftar file backup |
| `make restore FILE=db-20261014-190000.sql.gz` | Pulihkan database. Service app/queue/scheduler dihentikan sementara. Minta konfirmasi `YA` |
| `make restore-storage FILE=storage-20261014-190000.tar.gz` | Pulihkan file lampiran |

**Penting:**

- **Folder `backups/` ada di server yang sama.** Backup ini tidak melindungi dari server yang rusak atau hilang. Salin secara berkala ke lokasi lain, mis. dengan cron `rclone sync ./backups remote:carbon-tracker-backups` atau `rsync` ke server lain. Isinya data pribadi user, jadi simpan di tempat yang terenkripsi dan aksesnya terbatas.
- **Uji restore minimal sebulan sekali.** Jalankan stack yang sama di server atau VM lain, salin satu pasang file backup ke `backups/`, lalu jalankan `make restore` dan `make restore-storage`, dan pastikan data bisa dibuka di aplikasi.
- Restore database menimpa tabel yang ada dengan isi backup. Data yang dibuat setelah waktu backup akan hilang.

## Monitoring error (Sentry)

Sentry bersifat opsional dan hanya aktif bila DSN diisi:

- **Backend:** isi `SENTRY_DSN` di `.env`, lalu `make up`. Container dibuat ulang dan konfigurasi dibaca lagi. Error yang dilaporkan Laravel ikut terkirim.
- **Frontend:** isi `VITE_SENTRY_DSN`, lalu `make build up`. DSN dimasukkan saat build SPA.
- `SENTRY_TRACES_SAMPLE_RATE` (mis. `0.1`) mengaktifkan pelacakan performa. Kosongkan bila tidak dipakai.
- Data pribadi (IP, cookie, user) tidak dikirim. `SENTRY_SEND_DEFAULT_PII` bernilai default `false`.

## Pemeriksaan dan log

- `make ps` menampilkan status dan healthcheck tiap service.
- `make logs` atau `make logs SERVICE=queue` menampilkan log. Laravel menulis log ke stderr container.
- `https://domain-anda/up` adalah health check Laravel dan mengembalikan 200 bila aplikasi siap. Cocok untuk uptime monitor.
- `make compose-check` memvalidasi `compose.yml` dan `.env`.

## Keamanan

- `.env` berisi semua rahasia. Pastikan izinnya `600` dan file ini tidak pernah di-commit.
- Karena `env_file`, container app, queue, dan scheduler menerima seluruh isi `.env`, termasuk `DB_ROOT_PASSWORD`. Bila ingin lebih ketat, ganti password root setelah instalasi pertama dan hapus variabelnya dari `.env`. Variabel itu hanya dipakai saat volume MySQL masih kosong.
- Perbarui image dasar secara berkala untuk menerima patch keamanan PHP, nginx, dan MySQL: `docker compose build --pull && make up`.
- MySQL, php-fpm, dan port internal lain tidak dibuka ke host. Hanya `web` atau `caddy` yang menerima koneksi dari luar.

## Masalah umum

| Gejala | Penyebab dan solusi |
| --- | --- |
| `DB_PASSWORD wajib diisi` saat menjalankan perintah | `.env` belum lengkap. Isi nilai yang diminta. |
| Laporan tidak pernah selesai, email tidak terkirim | Service `queue` mati. Cek `make ps` dan `make logs SERVICE=queue`. |
| Login gagal setelah mengganti `DB_PASSWORD` | Password MySQL hanya diset saat volume kosong. Ubah juga di MySQL (`ALTER USER ...`) atau kembalikan nilai lama. |
| Sertifikat HTTPS tidak terbit | DNS belum mengarah ke server atau port 80/443 tertutup. Cek `make logs SERVICE=caddy`. |
| Perubahan `.env` tidak terpakai | Container perlu dibuat ulang (`make up`). Untuk `VITE_*` perlu `make build up`. |
