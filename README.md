# Carbon Footprint Tracker — Backend

REST API (`/api/v1`) untuk Carbon Footprint Tracker, dibangun dengan Laravel 13, autentikasi token Sanctum, queue untuk pembuatan laporan, dan Laravel Excel untuk import/export. Frontend-nya ada di repo terpisah: [carbon-tracker-frontend](https://github.com/arianto-sketch/carbon-tracker-frontend).

## Prasyarat

- PHP `^8.3` dan Composer.
- Database:
  - SQLite (default `.env.example`), atau
  - MySQL 8. MySQL dipakai di production, jadi uji di MySQL sebelum rilis (lihat [Test di MySQL](#test-di-mysql)).

> **Windows + Laragon:** PHP Laragon tidak otomatis ada di PATH Git Bash. Tambahkan dulu, misalnya `export PATH="/c/laragon/bin/php/php-8.3.x-Win32-vs16-x64:$PATH"`.

## Setup lokal

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite      # kalau memakai SQLite; untuk MySQL isi DB_* di .env
php artisan migrate --seed
php artisan serve                   # http://127.0.0.1:8000
```

`--seed` membuat kategori, faktor emisi, dan dua akun dengan password `password`:

| Email | Role |
|---|---|
| `admin@logique.co.id` | admin |
| `arianto@logique.co.id` | pm |

### Proses latar

- **Queue** (`QUEUE_CONNECTION=database`): laporan dan email notifikasi approval dibuat oleh job, jadi jalankan `php artisan queue:work`. Tanpa worker, laporan tidak selesai dan email tidak terkirim (notifikasi in-app tetap muncul). Untuk development atau E2E, `QUEUE_CONNECTION=sync` membuat keduanya langsung diproses.
- **Email**: isi `MAIL_*` dan `FRONTEND_URL` (tautan "Buka project" di email). User bisa mematikan email notifikasi dan email ringkasan bulanan di halaman Profil.
- **Scheduler**: pasang cron `* * * * * php artisan schedule:run`. Setiap hari, scheduler membersihkan:
  - token Sanctum yang kedaluwarsa,
  - file temp import Laravel Excel,
  - file lampiran dari entri yang sudah dihapus lebih dari 30 hari (`entries:purge-deleted-attachments`, bisa juga dijalankan manual dengan `--days=N`).

  Scheduler juga memperbarui nilai faktor emisi "saat ini" setiap pukul 00.05 WIB (`factors:sync-current-values`). Setiap tanggal 1 pukul 07.00 WIB, scheduler mengirim email ringkasan emisi bulan lalu ke owner project dan admin (`reports:send-monthly-summaries`). Perintah ini bisa dijalankan manual dengan `--month=YYYY-MM`, `--dry-run` (lihat penerima tanpa mengirim), atau `--force` (kirim ulang periode yang sudah terkirim).

### Batas & keamanan API

- Endpoint ber-auth dibatasi **120 request per menit per user** (`API_RATE_LIMIT`), dan respons 429 berformat JSON. Login punya batas sendiri: 5 per menit per email + IP.
- Semua respons mengirim `X-Content-Type-Options: nosniff`.

## Test

### Format & analisis statis

```bash
composer lint        # Pint (preset laravel; perataan => di array dipertahankan, lihat pint.json)
vendor/bin/pint      # merapikan format otomatis
composer analyse     # Larastan level 5 (phpstan.neon)
```

Relasi model wajib punya return type dan generic, misalnya `/** @return BelongsTo<Project, $this> */`. Resource API diberi `@mixin` ke modelnya, supaya Larastan mengenali properti dan relasinya.

### PHPUnit (default: SQLite in-memory)

```bash
php artisan test
php artisan test --filter=EntryAttachmentTest     # satu file / satu test
```

`phpunit.xml` memakai SQLite `:memory:`, jadi tidak ada database yang tersentuh.

### Test di MySQL

Beberapa perilaku hanya benar-benar teruji di MySQL:
- `lockForUpdate` diabaikan oleh SQLite.
- Perubahan enum dan index di migration.

Jalankan suite yang sama di MySQL dengan meng-override env dari shell (env shell menang atas `.env` dan `phpunit.xml`):

```bash
# PERINGATAN: RefreshDatabase mengosongkan database ini. Jangan pakai database development/production.
mysql -uroot -e "CREATE DATABASE carbon_tracker_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_CONNECTION=mysql DB_DATABASE=carbon_tracker_test DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test
```

- Test yang menjalankan DDL (misalnya uji rollback migration) hanya berjalan di SQLite dan otomatis di-skip di MySQL, karena DDL di MySQL meng-commit transaksi test.
- Race condition (dua request bersamaan) tidak bisa direproduksi oleh PHPUnit yang berjalan sekuensial. Test otomatis hanya membuktikan bahwa status dibaca ulang dari database. Lock-nya sendiri diverifikasi manual dengan dua koneksi MySQL; contoh skenarionya ada di deskripsi PR #11.

### Migration di MySQL

Sebelum rilis yang membawa migration baru, uji jalur upgrade di database salinan. Jangan memakai `migrate:fresh`:

```bash
php artisan migrate                      # di atas data lama
php artisan migrate:rollback --step=N    # N = jumlah migration baru
php artisan migrate
```

### E2E (Playwright)

Spec E2E ada di repo frontend dan berjalan terhadap backend ini. Siapkan backend dengan **database khusus E2E**, karena setiap run membuat data baru:

```bash
php artisan migrate:fresh --seed
QUEUE_CONNECTION=sync API_RATE_LIMIT=1000 php artisan serve --host=127.0.0.1 --port=8000
```

`API_RATE_LIMIT=1000` diperlukan karena akun PM di E2E mencapai sekitar 120 request per menit, yaitu tepat di batas default. Perilaku rate limit sendiri diuji oleh PHPUnit (`ApiRateLimitTest`).

Lalu ikuti bagian *Test E2E* di [README frontend](https://github.com/arianto-sketch/carbon-tracker-frontend#test-e2e-playwright).

## Deploy production

Stack Docker untuk VPS (nginx + SPA, PHP-FPM, queue, scheduler, MySQL, backup harian 14 hari, HTTPS opsional via Caddy) dan langkah-langkahnya ada di [docs/DEPLOY.md](docs/DEPLOY.md). Ringkasnya: `make setup`, `make create-admin EMAIL=...`, `make seed-master`; rilis berikutnya cukup `make deploy`. Error production bisa dikirim ke Sentry dengan mengisi `SENTRY_DSN`.

## CI (GitHub Actions)

| Workflow | Kapan jalan | Isi |
|---|---|---|
| `Tests` (`.github/workflows/tests.yml`) | Setiap PR dan push ke `master` | Pint + Larastan, lalu PHPUnit dengan PHP 8.3 di SQLite dan di MySQL 8.4 |
| `E2E` (`.github/workflows/e2e.yml`) | Setiap PR dan push ke `master`, dan **setiap hari** | E2E Playwright frontend `main` terhadap backend dari PR/branch ini (MySQL 8.4) |
| `Security audit` (`.github/workflows/security-audit.yml`) | PR yang mengubah `composer.json`/`composer.lock`, push ke `master`, dan **setiap Senin** | `composer audit --locked` |
| `Docker` (`.github/workflows/docker.yml`) | Setiap PR dan push ke `master` | Build image production, `make setup`, lalu uji asap: SPA lewat nginx, API, queue worker, backup + restore |

- Audit dijadwalkan mingguan karena advisory baru bisa muncul walau kode tidak berubah.
- E2E jalan di kedua arah: repo ini menguji backend terhadap frontend `main`, dan CI repo frontend menguji frontend terhadap `master` backend. Run E2E harian menangkap perubahan di repo frontend yang memengaruhi backend.
- Branch protection `master` mewajibkan check `PHPUnit (sqlite)`, `PHPUnit (mysql)`, `E2E (Playwright)`, `Pint & Larastan`, dan `Docker (compose smoke)` lulus sebelum merge, dan push langsung ke `master` ditolak. Dependabot alerts dan PR security update otomatis aktif.
