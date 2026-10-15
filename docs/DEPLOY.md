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
- Firewall untuk SSH, mis. `ufw allow OpenSSH && ufw allow 80,443/tcp && ufw enable`. Catatan: port yang dibuka Docker melewati aturan ufw. Yang aman di sini karena compose hanya membuka 80/443 (atau `HTTP_PORT`).

## Instalasi pertama

```bash
git clone https://github.com/arianto-sketch/carbon-tracker-backend.git carbon-tracker
cd carbon-tracker
make frontend          # clone repo frontend ke ./carbon-tracker-frontend
make .env              # membuat .env (chmod 600) lalu sengaja berhenti agar nilainya diisi dulu
make key               # salin hasilnya ke APP_KEY di .env
```

Lalu buka `.env` dan isi semua nilai `<...>`:

- `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`: `https://domain-anda`
- `DB_PASSWORD`: password acak yang panjang, mis. hasil `openssl rand -base64 24`. `DB_USERNAME` tidak boleh `root`.
  Password root MySQL tidak ada di `.env`. `make setup` membuatnya acak di `secrets/db_root_password` (izin `600`), dan container app tidak menerimanya.
- `MAIL_*`: kredensial SMTP
- HTTPS: isi `DOMAIN` dan `ACME_EMAIL`, lalu aktifkan baris `COMPOSE_FILE=compose.yml:compose.tls.yml`. Tanpa baris itu, aplikasi berjalan di HTTP port `HTTP_PORT`. Itu cocok bila TLS diurus di luar Docker, mis. load balancer atau Cloudflare.

Setelah itu jalankan:

```bash
make setup                                                # password root, build image, migrate, start semua service
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

Perintah ini menjalankan `git pull` untuk backend dan frontend, build image, **backup**, lalu migration dengan image baru, lalu mengganti container. Worker antrean diberi waktu 60 detik untuk menyelesaikan job yang sedang berjalan.

Selama migration berjalan, container lama masih melayani request. Karena itu migration sebaiknya hanya **menambah**: kolom dan tabel baru boleh, sedangkan menghapus atau mengganti nama kolom yang masih dipakai kode lama dilakukan di rilis berikutnya.

- **Memakai tag:** isi `APP_VERSION` di `.env` dengan versi rilis (mis. `v1.4.0`) sebelum `make deploy`. Image lama tetap tersimpan dengan tag versinya.
- **Rollback:**
  1. Checkout versi sebelumnya (`git checkout v1.3.0`, begitu juga di folder frontend bila perlu).
  2. Kembalikan `APP_VERSION`.
  3. Jalankan `make build up`.
  4. Bila rilis yang dibatalkan berisi migration, pulihkan database dari backup yang dibuat `make deploy` sebelum migrate (lihat bagian berikut).
  5. Setelah perbaikan siap, kembali ke branch utama (`git checkout master`, dan `main` di folder frontend) sebelum `make deploy` berikutnya. Kalau tidak, `git pull` gagal karena posisi checkout tidak berada di branch.

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
| `make restore FILE=db-20261014-190000.sql.gz` | Pulihkan database persis seperti isi backup. Kondisi saat ini disimpan dulu sebagai `db-...-pre-restore.sql.gz`, lalu semua tabel dihapus (termasuk tabel dari migration setelah backup) dan isi backup dimuat. Service app/queue/scheduler dihentikan sementara. Minta konfirmasi `YA` |
| `make restore-storage FILE=storage-20261014-190000.tar.gz` | Pulihkan file lampiran |

**Penting:**

- **File backup hanya bisa dibaca root** (izin `600`), karena isinya data pribadi user (email, hash password). Gunakan `sudo` untuk menyalinnya.
- **Folder `backups/` ada di server yang sama.** Backup ini tidak melindungi dari server yang rusak atau hilang. Salin secara berkala ke lokasi lain, mis. dengan cron `rclone sync ./backups remote:carbon-tracker-backups` atau `rsync` ke server lain. Isinya data pribadi user, jadi simpan di tempat yang terenkripsi dan aksesnya terbatas.
- **Uji restore minimal sebulan sekali.** Jalankan stack yang sama di server atau VM lain, salin satu pasang file backup ke `backups/`, lalu jalankan `make restore` dan `make restore-storage`, dan pastikan data bisa dibuka di aplikasi.
- Restore mengembalikan database ke kondisi saat backup. Data yang dibuat setelah waktu backup hilang, tetapi masih ada di file `*-pre-restore.sql.gz`.

## Monitoring error (Sentry)

Sentry bersifat opsional dan hanya aktif bila DSN diisi:

- **Backend:** isi `SENTRY_DSN` di `.env`, lalu `make up`. Container dibuat ulang dan konfigurasi dibaca lagi. Error yang dilaporkan Laravel ikut terkirim.
- **Frontend:** isi `VITE_SENTRY_DSN`, lalu `make build up`. DSN dimasukkan saat build SPA.
- `SENTRY_TRACES_SAMPLE_RATE` (mis. `0.1`) mengaktifkan pelacakan performa. Kosongkan bila tidak dipakai.
- Data pribadi tidak dikirim. Backend: IP, cookie, dan user dimatikan (`SENTRY_SEND_DEFAULT_PII=false`), dan body request (yang berisi password saat login) tidak pernah dilampirkan. Frontend: data user, cookie, header (token API), body, dan query string dimatikan. Untuk menyamarkan IP pengirim, aktifkan juga opsi *Prevent Storing of IP Addresses* di pengaturan project Sentry.

## Pemeriksaan dan log

- `make ps` menampilkan status dan healthcheck tiap service.
- `make logs` atau `make logs SERVICE=queue` menampilkan log. Laravel menulis log ke stderr container.
- `https://domain-anda/up` adalah health check Laravel dan mengembalikan 200 bila aplikasi siap. Cocok untuk uptime monitor.
- `make compose-check` memvalidasi `compose.yml` dan `.env`.

## Keamanan

- `.env` berisi semua rahasia. Pastikan izinnya `600` dan file ini tidak pernah di-commit.
- Password root MySQL ada di `secrets/db_root_password`, bukan di `.env`. Container app, queue, dan scheduler tidak menerimanya. Password itu hanya dipakai saat volume MySQL pertama kali dibuat.
- IP klien di audit log dan rate limit adalah IP koneksi ke nginx, atau IP dari header `X-Forwarded-For` bila koneksi datang dari Caddy (`172.28.0.10`). Bila ada proxy lain di depan (mis. Cloudflare atau load balancer), tambahkan rentang IP proxy itu sebagai `set_real_ip_from` di `docker/web/default.conf`. Tanpa itu, semua user terlihat ber-IP sama dan berbagi batas rate limit. Bila subnet `172.28.0.0/24` bentrok dengan jaringan server, ubah di `compose.yml`, `compose.tls.yml`, dan `docker/web/default.conf`.
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
