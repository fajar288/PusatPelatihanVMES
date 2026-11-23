# Panduan deploy ke cPanel

Panduan ini bisa diikuti orang yang belum pernah memakai cPanel. Hosting memakai cPanel (tanpa Docker, tanpa supervisor, tanpa proses latar yang berjalan terus). Semua build dilakukan di GitHub Actions; server hanya menerima artefak jadi.

```
pengunjung ──> pusatpelatihan.id ──────────> Next.js (Setup Node.js App)  ~/ppti/frontend/current/server.js
                                                  │  API_URL_INTERNAL
pengunjung ──> api.pusatpelatihan.id ──────> Laravel (PHP, document root) ~/ppti/backend/current/public
               (panel admin /admin, API)          │
                                                  ├─ MySQL/MariaDB
                                                  └─ cron: schedule:run + queue:work tiap menit
```

Daftar isi: [1. Syarat hosting](#1-syarat-hosting) · [2. Struktur domain & folder](#2-struktur-domain--folder) · [3. Database](#3-database) · [4. Isi .env produksi](#4-isi-env-produksi) · [5. Persiapan GitHub](#5-persiapan-github) · [6. Deploy pertama](#6-deploy-pertama) · [7. Cron](#7-cron) · [8. Deploy berikutnya](#8-deploy-berikutnya) · [9. Rollback](#9-rollback) · [10. Backup & restore](#10-backup--restore) · [11. Pemantauan](#11-pemantauan) · [12. Pemecahan masalah](#12-pemecahan-masalah) · [13. Yang belum diuji](#13-yang-belum-diuji-tanpa-server)

---

## 1. Syarat hosting

Konfirmasi ke penyedia hosting **sebelum membeli paket**. Tanpa butir yang ditandai "wajib", aplikasi tidak bisa berjalan.

| Kebutuhan | Keterangan | Status |
|---|---|---|
| **Setup Node.js App** dengan Node.js **20.9 atau lebih baru** | Wajib untuk frontend Next.js 16 (semua halaman publik, portal, server action). Menu ini ada di cPanel bila hosting memakai CloudLinux + Passenger. | wajib |
| **PHP 8.3** (atau 8.4) | Wajib. Ekstensi: `pdo_mysql`, `mbstring`, `intl`, `gd` (dengan WebP), `zip`, `exif`, `bcmath`, `fileinfo`, `openssl`, `curl`, `tokenizer`, `xml`, `ctype`, `json`, `sodium`. Cek di menu **Select PHP Version** atau **MultiPHP Manager**. | wajib |
| **MySQL 8.0.16+ atau MariaDB 10.4+** | Wajib. PostgreSQL juga didukung bila tersedia. Detail: [docs/database.md](database.md). | wajib |
| **Terminal** (menu Terminal) atau **SSH** | Wajib untuk menjalankan skrip rilis dan `php artisan`. | wajib |
| **Cron Jobs** dengan interval tiap menit | Wajib (scheduler & antrean). Beberapa paket murah membatasi minimal 5 atau 15 menit. | wajib |
| **AutoSSL** (Let's Encrypt/Sectigo) | Wajib untuk HTTPS kedua domain. | wajib |
| **Subdomain** dengan document root bebas | Untuk `api.pusatpelatihan.id` -> `ppti/backend/current/public`. | wajib |
| Memori proses Node.js minimal **512 MB** (disarankan 1 GB) | Next.js berjalan terus di Passenger. Build tidak dilakukan di server. | wajib |
| `memory_limit` PHP minimal **256M**, `upload_max_filesize` & `post_max_size` minimal **25M** | Unggah dokumen pendaftaran (maks. 5 x 4 MB). | wajib |
| Kuota disk minimal **5 GB** (disarankan 10 GB) | Kode 2 aplikasi x beberapa rilis (~600 MB), unggahan, backup 7 harian + 4 mingguan. | wajib |
| `mysqldump` tersedia di cron (`which mysqldump`) | Untuk backup otomatis. Bila tidak ada, pakai Backup cPanel. | disarankan |
| Akses Email Accounts / SMTP | Email transaksional (verifikasi, invoice, notifikasi). | disarankan |
| Symlink diizinkan (`SymLinksIfOwnerMatch`) | Dipakai struktur rilis; bila dilarang lihat [alternatif tanpa symlink](#alternatif-bila-symlink-tidak-diizinkan). | disarankan |

Paket cPanel yang dicek pada November 2026 untuk domain ini **tidak memiliki** Setup Node.js App, PostgreSQL, maupun Terminal, dan kuota disknya 1 GB. Paket itu belum bisa menjalankan aplikasi ini; perlu upgrade paket atau pindah ke paket yang memenuhi tabel di atas.

Cara mengecek sendiri setelah login cPanel: ketik "Node" di kotak pencarian cPanel (harus muncul **Setup Node.js App**), ketik "Terminal", lalu buka **MySQL Databases** dan **Cron Jobs**.

## 2. Struktur domain & folder

| Alamat | Aplikasi | Di cPanel |
|---|---|---|
| `https://pusatpelatihan.id` (dan `www`) | Frontend Next.js | Setup Node.js App, Application URL = domain utama |
| `https://api.pusatpelatihan.id` | Backend Laravel: API `/api/v1`, panel admin `/admin`, verifikasi email | Subdomain dengan Document Root `ppti/backend/current/public` |

Folder di home akun (`/home/<akun>`), dibuat otomatis oleh skrip rilis kecuali yang bertanda (manual):

```
~/ppti/
├── scripts/deploy/            skrip rilis (unggah manual sekali, lihat 6.2)
├── incoming/                  tempat artefak yang diunggah
├── backend/
│   ├── releases/<versi>/      kode per rilis
│   ├── shared/.env            (manual) konfigurasi produksi
│   ├── shared/storage/        unggahan, log, cache, backup (bertahan antar rilis)
│   └── current -> releases/<versi>
└── frontend/
    ├── releases/<versi>/      hasil build standalone
    ├── shared/.env.production (manual) variabel server
    └── current -> releases/<versi>
```

`public_html` tidak dipakai untuk aplikasi ini (biarkan kosong atau berisi halaman bawaan). Folder `ppti` berada **di luar** `public_html`, sehingga `.env`, kode, dan backup tidak pernah bisa dibuka lewat web.

## 3. Database

1. cPanel > **MySQL Databases**.
2. **Create New Database**: isi `ppti` (cPanel menambahkan awalan akun, mis. `akunanda_ppti`). Catat nama lengkapnya.
3. **MySQL Users > Add New User**: nama `ppti`, buat sandi dengan **Password Generator** (min. 20 karakter). Catat.
4. **Add User To Database**: pilih user & database tadi, centang **ALL PRIVILEGES**, Make Changes.
5. Charset bawaan cPanel sudah `utf8mb4`. Tidak perlu membuat tabel apa pun: migrasi yang membuatnya.

## 4. Isi .env produksi

### Backend (`~/ppti/backend/shared/.env`)

Salin dari [`backend/.env.production.example`](../backend/.env.production.example). Setiap variabel diberi komentar di berkas itu; yang paling penting:

| Variabel | Isi | Keterangan |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | `true` membuka detail galat ke pengunjung. |
| `APP_KEY` | dibuat `php artisan key:generate` | Jangan diganti setelah ada data (enkripsi & sidik nama sertifikat memakainya). Simpan salinannya di pengelola sandi. |
| `APP_URL` | `https://api.pusatpelatihan.id` | URL backend, tanpa `/` di akhir. |
| `FRONTEND_URL` | `https://pusatpelatihan.id` | CORS, tautan di email, webhook revalidasi. Tambah `https://www.pusatpelatihan.id` dipisah koma bila www dipakai. |
| `SANCTUM_STATEFUL_DOMAINS` | `pusatpelatihan.id,www.pusatpelatihan.id` | Domain yang boleh memakai cookie sesi Sanctum. |
| `SESSION_DOMAIN` | kosong | Cookie panel admin hanya untuk subdomain API. Frontend memakai token, bukan cookie bersama. |
| `SESSION_SECURE_COOKIE` | `true` | Cookie hanya lewat HTTPS. |
| `REVALIDATE_SECRET` | acak 64 karakter | **Sama persis** dengan frontend. Buat: `php -r "echo bin2hex(random_bytes(32));"` |
| `API_PROXY_SECRET` | acak 64 karakter | **Sama persis** dengan frontend. Tanpa ini pembatas laju per IP menganggap semua pengunjung satu IP. |
| `DB_*` | dari langkah 3 | `DB_HOST=localhost`. |
| `MAIL_*` | akun email cPanel atau SMTP penyedia | Buat akun `noreply@pusatpelatihan.id` di **Email Accounts**; host `mail.pusatpelatihan.id`, port 465, `MAIL_SCHEME=smtps`. |
| `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` | `database` | Tidak butuh Redis. |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | email nyata & sandi kuat (min. 12) | Hanya untuk membuat super admin pertama; kosongkan `ADMIN_PASSWORD` sesudahnya. |
| `WHATSAPP_ENABLED`, `CHATBOT_ENABLED`, `AI_*` | `false` | **Tetap mati.** WhatsApp diaktifkan pemilik proyek (docs/whatsapp-fonnte.md); AI menunggu persetujuan. |
| `BACKUP_ENABLED` | `true` | Backup database tiap malam (bagian 10). |

Setelah mengubah `.env` produksi: jalankan `php artisan config:cache` dari `~/ppti/backend/current`.

### Frontend

Ada dua jenis variabel ([`frontend/.env.production.example`](../frontend/.env.production.example)):

| Variabel | Diisi di | Keterangan |
|---|---|---|
| `NEXT_PUBLIC_SITE_URL` | GitHub > Settings > Secrets and variables > Actions > **Variables** | `https://pusatpelatihan.id`. Ditanam saat build. |
| `NEXT_PUBLIC_API_URL` | GitHub Variables | `https://api.pusatpelatihan.id/api/v1`. Ditanam saat build; dipakai browser. |
| `API_URL_INTERNAL` | `~/ppti/frontend/shared/.env.production` | URL API untuk server Next. Biasanya sama dengan `NEXT_PUBLIC_API_URL`. |
| `REVALIDATE_SECRET` | `shared/.env.production` | Sama dengan backend. |
| `API_PROXY_SECRET` | `shared/.env.production` | Sama dengan backend. |
| `NODE_ENV` | `shared/.env.production` | `production`. |

Mengubah variabel `NEXT_PUBLIC_*` butuh build & rilis ulang. Variabel lain cukup diubah lalu **Restart** aplikasi di Setup Node.js App.

## 5. Persiapan GitHub

1. Repository > **Settings > Secrets and variables > Actions > Variables**, tambahkan:
   - `NEXT_PUBLIC_SITE_URL` = `https://pusatpelatihan.id`
   - `NEXT_PUBLIC_API_URL` = `https://api.pusatpelatihan.id/api/v1`
   - `CPANEL_NODE_VERSION` = versi mayor Node yang dipilih di Setup Node.js App (mis. `20` atau `22`)
   - `CPANEL_PHP_VERSION` = `8.3` (samakan dengan versi PHP hosting)
2. Membuat rilis: **Actions > Rilis (artefak cPanel) > Run workflow** (isi versi, mis. `v1.0.0`), atau buat tag git `v1.0.0` lalu push. Untuk tag, artefak juga dilampirkan ke halaman **Releases**.
3. Unduh artefak: buka hasil workflow > bagian **Artifacts** > `ppti-backend-<versi>` dan `ppti-frontend-<versi>`. GitHub membungkus tiap artefak dalam `.zip`; ekstrak sekali di komputer Anda untuk mendapatkan `ppti-backend-<versi>.tar.gz` dan `ppti-frontend-<versi>.tar.gz`. Artefak Actions disimpan 90 hari; artefak di Releases tidak kedaluwarsa.

Deploy otomatis lewat SSH sudah disiapkan di workflow (job `deploy`) tetapi **mati**. Nyalakan nanti dengan variabel `CPANEL_DEPLOY_ENABLED=true` dan secrets `CPANEL_SSH_HOST`, `CPANEL_SSH_PORT`, `CPANEL_SSH_USER`, `CPANEL_SSH_KEY` (kunci privat khusus deploy), `CPANEL_SSH_KNOWN_HOSTS` (hasil `ssh-keyscan -p <port> <host>`), dan environment `production` (bisa diberi persetujuan manual).

## 6. Deploy pertama

### 6.1 Domain, SSL, PHP

1. cPanel > **Domains > Create A New Domain**: `api.pusatpelatihan.id`, **hapus centang** "Share document root", Document Root: `ppti/backend/current/public`. (cPanel membuat folder kosong itu; skrip rilis menggantinya dengan symlink ke rilis aktif.)
2. Pastikan DNS `pusatpelatihan.id`, `www`, dan `api` mengarah ke IP hosting (menu **Zone Editor**).
3. **SSL/TLS Status** > Run AutoSSL. Tunggu sampai ketiga nama bergembok hijau.
4. **Select PHP Version** (atau MultiPHP Manager): PHP 8.3 untuk `api.pusatpelatihan.id`, aktifkan ekstensi di bagian 1. Di **Options** / MultiPHP INI Editor: `memory_limit=256M`, `upload_max_filesize=25M`, `post_max_size=25M`, `max_execution_time=60`.

### 6.2 Unggah skrip & artefak

1. **File Manager** > home > buat folder `ppti`, di dalamnya `scripts/deploy` dan `incoming`.
2. Unggah isi folder `scripts/deploy/` dari repositori (`lib.sh`, `release-backend.sh`, `release-frontend.sh`, `rollback.sh`) ke `~/ppti/scripts/deploy/`.
3. Unggah `ppti-backend-<versi>.tar.gz` dan `ppti-frontend-<versi>.tar.gz` ke `~/ppti/incoming/`.

### 6.3 .env backend

Buka **Terminal** cPanel:

```bash
mkdir -p ~/ppti/backend/shared
tar -xzf ~/ppti/incoming/ppti-backend-<versi>.tar.gz -O ppti-backend-<versi>/.env.production.example > ~/ppti/backend/shared/.env
chmod 600 ~/ppti/backend/shared/.env
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"   # salin hasilnya untuk APP_KEY
nano ~/ppti/backend/shared/.env      # isi sesuai bagian 4 (termasuk APP_KEY), simpan: Ctrl+O, Enter, Ctrl+X
```

`php` di Terminal cPanel bisa berbeda versi dari PHP web. Cek: `php -v`. Bila bukan 8.3, pakai path lengkap, mis. `/opt/cpanel/ea-php83/root/usr/bin/php` (EasyApache) atau `/opt/alt/php83/usr/bin/php` (CloudLinux), dan jalankan skrip dengan `PHP_BIN=<path> bash ...`.

### 6.4 Rilis backend & perintah sekali jalan

```bash
cd ~/ppti
bash scripts/deploy/release-backend.sh incoming/ppti-backend-<versi>.tar.gz
```

Folder kosong `ppti/backend/current/public` yang dibuat cPanel saat subdomain dibuat otomatis diganti symlink `current -> releases/<versi>` (cek: `ls -la ~/ppti/backend/`). Bila `current` ternyata berisi berkas, skrip berhenti sebelum menyentuh database.

Lalu sekali saja, dari `~/ppti/backend/current`:

```bash
cd ~/ppti/backend/current
php artisan config:clear                                  # seeder membaca .env langsung
php artisan db:seed --class=RolesSeeder --force           # peran & izin (ulangi tiap ada izin baru)
php artisan db:seed --class=AdminUserSeeder --force       # super admin dari ADMIN_EMAIL/ADMIN_PASSWORD
php artisan db:seed --class=CmsSeeder --force             # konten awal situs (hanya instalasi baru)
php artisan db:seed --class=CompetencySeeder --force      # master kompetensi
php artisan identity:backfill                              # tautkan data identitas lama (aman diulang)
php artisan files:privatize --dry-run && php artisan files:privatize   # berkas pribadi ke disk privat
php artisan webpush:vapid                                  # kunci push notifikasi (tempel ke .env bila diminta)
php artisan config:cache
```

Kemudian kosongkan `ADMIN_PASSWORD` di `shared/.env`, `php artisan config:cache`, login ke `https://api.pusatpelatihan.id/admin`, dan ganti sandi di panel.

Uji: `curl -s https://api.pusatpelatihan.id/api/v1/health/ready` harus berisi `"database":"ok"`.

### 6.5 Rilis frontend & Setup Node.js App

```bash
mkdir -p ~/ppti/frontend/shared
nano ~/ppti/frontend/shared/.env.production     # isi bagian 4 (API_URL_INTERNAL, REVALIDATE_SECRET, API_PROXY_SECRET, NODE_ENV)
chmod 600 ~/ppti/frontend/shared/.env.production
bash ~/ppti/scripts/deploy/release-frontend.sh ~/ppti/incoming/ppti-frontend-<versi>.tar.gz
```

cPanel > **Setup Node.js App > Create Application**:

| Isian | Nilai |
|---|---|
| Node.js version | sama dengan `CPANEL_NODE_VERSION` (20.9+) |
| Application mode | Production |
| Application root | `ppti/frontend/current` |
| Application URL | `pusatpelatihan.id` |
| Application startup file | `server.js` |
| Environment variables | opsional; sama dengan `shared/.env.production` |

Klik **Create**, lalu **Restart**. **Jangan** klik "Run NPM Install": hasil build standalone sudah membawa dependensinya. Buka `https://pusatpelatihan.id`.

### 6.6 Keamanan

- **HTTPS & HSTS**: backend (middleware `SecurityHeaders`) dan frontend (`next.config.ts`) mengirim `Strict-Transport-Security` otomatis bila diakses lewat HTTPS. Paksa HTTPS: cPanel > **Domains**, nyalakan **Force HTTPS Redirect** untuk ketiga nama.
- **Webhook Fonnte** (dikerjakan pemilik proyek saat WhatsApp diaktifkan): URL `https://api.pusatpelatihan.id/api/webhooks/fonnte/<FONNTE_WEBHOOK_SECRET>/status` dan `.../device`, panduan lengkap di docs/whatsapp-fonnte.md.
- Pastikan `~/ppti/backend/shared/.env` dan `~/ppti/frontend/shared/.env.production` ber-izin `600`.

## 7. Cron

cPanel > **Cron Jobs** > Add New Cron Job, Common Settings **Once Per Minute (`* * * * *`)**. Ganti `<akun>` dan path PHP sesuai hasil `which php` di Terminal.

```cron
# Scheduler Laravel: notifikasi, pengingat, retensi, backup malam, detak kesiapan
* * * * * cd /home/<akun>/ppti/backend/current && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1

# Antrean (pengganti worker/supervisor): berhenti saat antrean kosong atau setelah 50 detik; flock mencegah tumpang tindih
* * * * * cd /home/<akun>/ppti/backend/current && /usr/bin/flock -n /home/<akun>/ppti/queue.lock /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
```

Isi **Cron Email** dengan email admin agar galat cron terkirim (atau biarkan `>> /dev/null` dan pantau lewat health check). Setelah 2 menit, `curl -s https://api.pusatpelatihan.id/api/v1/health/ready` harus menunjukkan `"scheduler":"ok"`.

## 8. Deploy berikutnya

1. Jalankan workflow **Rilis** (atau push tag `v1.0.1`).
2. Unggah dua artefak `.tar.gz` ke `~/ppti/incoming/`.
3. Terminal:
   ```bash
   cd ~/ppti
   bash scripts/deploy/release-backend.sh incoming/ppti-backend-v1.0.1.tar.gz
   bash scripts/deploy/release-frontend.sh incoming/ppti-frontend-v1.0.1.tar.gz
   ```
4. Bila rilis menambah izin baru: `cd ~/ppti/backend/current && php artisan config:clear && php artisan db:seed --class=RolesSeeder --force && php artisan config:cache`.
5. Hapus artefak lama di `incoming/` (skrip sudah menyimpan 5 rilis terakhir di `releases/`).

Yang dilakukan `release-backend.sh`: ekstrak ke `releases/<versi>` (folder sementara lalu rename), tautkan `shared/.env` & `shared/storage`, mode pemeliharaan, `migrate --force`, `storage:link`, `config:cache`, `route:cache`, `view:cache`, `event:cache`, alihkan `current` secara atomik, `queue:restart`, keluar dari mode pemeliharaan, hapus rilis lama (sisakan 5). Skrip memakai `set -euo pipefail`: bila satu langkah gagal sebelum `current` dialihkan, rilis lama tetap aktif dan mode pemeliharaan dimatikan. Menjalankan ulang versi yang sudah aktif tidak melakukan apa-apa; versi yang gagal di tengah jalan diekstrak ulang dari awal.

`release-frontend.sh`: ekstrak, periksa `server.js` & `.next/static`, tautkan `shared/.env.production`, alihkan `current`, sentuh `tmp/restart.txt` agar Passenger memuat ulang. Bila halaman belum berubah, klik **Restart** di Setup Node.js App.

## 9. Rollback

```bash
bash ~/ppti/scripts/deploy/rollback.sh backend            # ke rilis aktif sebelumnya
bash ~/ppti/scripts/deploy/rollback.sh frontend           # idem untuk frontend
bash ~/ppti/scripts/deploy/rollback.sh backend v1.0.0     # ke versi tertentu yang masih ada di releases/
```

Rollback hanya memindahkan `current` (beberapa detik). **Skema database tidak ikut mundur.** Bila rilis yang dibatalkan menambah migrasi yang tidak cocok dengan kode lama:

1. Sebelum rollback, dari folder rilis baru: `php artisan migrate:rollback --step=<jumlah migrasi rilis itu>` (lihat `php artisan migrate:status`), atau
2. pulihkan database dari backup sebelum rilis (bagian 10). Karena itu, jalankan `php artisan backup:database` **tepat sebelum** setiap rilis yang memuat migrasi.

### Alternatif bila symlink tidak diizinkan

Jalankan skrip dengan `PPTI_NO_SYMLINK=1`:

```bash
PPTI_NO_SYMLINK=1 bash scripts/deploy/release-backend.sh incoming/ppti-backend-<versi>.tar.gz
```

`current` menjadi **salinan** rilis (bukan symlink), rilis sebelumnya dipindah ke `current.prev`; `.env` disalin dan storage bersama ditunjuk lewat `LARAVEL_STORAGE_PATH` di `.env` rilis. Untuk `public/storage` (berkas unggahan publik), buat **Alias** di Apache lewat penyedia hosting atau set `FILESYSTEM_DISK` ke object storage. Mode ini sudah diuji skrip ujinya, tetapi belum di server cPanel sungguhan.

## 10. Backup & restore

### Backup database otomatis

`php artisan backup:database` membuat dump terkompresi (`mysqldump --single-transaction`, `pg_dump`, atau salinan SQLite) di `~/ppti/backend/shared/storage/app/backups/` (di luar `public`, dan diberi `.htaccess` deny):

```
backups/daily/ppti-db-20261124-013000.sql.gz     7 terakhir (BACKUP_KEEP_DAILY)
backups/weekly/ppti-db-20261122-013000.sql.gz    4 terakhir (BACKUP_KEEP_WEEKLY), dibuat hari Minggu
```

Terjadwal tiap malam pukul `BACKUP_TIME` (01:30) bila `BACKUP_ENABLED=true`. Kata sandi database tidak muncul di daftar proses (dioper lewat variabel lingkungan). Perintah manual:

```bash
cd ~/ppti/backend/current
php artisan backup:database            # sekarang juga (mis. sebelum rilis)
php artisan backup:database --files    # ikut mengarsipkan storage/app/private (dokumen, bukti bayar, tugas, foto)
php artisan backup:database --list     # daftar backup tersimpan
```

### Berkas unggahan

Dua pilihan, pakai salah satu:

1. `BACKUP_INCLUDE_PRIVATE_FILES=true`: arsip `ppti-files-*.tar.gz` dibuat bersama dump database (perhatikan kuota disk).
2. cPanel > **Backup > Download a Home Directory Backup** (manual) atau **JetBackup** bila disediakan hosting (otomatis, termasuk database). Folder penting: `ppti/backend/shared/storage/app` dan `ppti/backend/shared/.env`.

### Salinan ke lokasi lain (offsite)

Backup di server yang sama hilang bila server rusak. Penyedia penyimpanan belum diputuskan; pilihan:

- **Manual**: unduh `backups/weekly/*` tiap minggu lewat File Manager ke drive kantor.
- **rclone** (bila diizinkan hosting) ke Google Drive/S3/Backblaze: tambah cron `30 2 * * * rclone copy ~/ppti/backend/shared/storage/app/backups remote:ppti-backup --max-age 2d`.
- **Remote FTP/SFTP backup** bawaan cPanel/JetBackup ke server lain.
- Object storage S3 kompatibel lewat `FILESYSTEM_DISK=s3` (sekaligus memindahkan unggahan).

### Restore

Selalu uji di database terpisah dulu bila memungkinkan.

MySQL/MariaDB:

```bash
cd ~/ppti/backend/current
php artisan down
gunzip -c ../shared/storage/app/backups/daily/ppti-db-20261124-013000.sql.gz \
  | mysql --host=localhost --user=akunanda_ppti -p akunanda_ppti
php artisan migrate --force        # bila backup lebih lama dari kode yang aktif
php artisan config:cache && php artisan up
```

Atau lewat cPanel > **phpMyAdmin** > pilih database > **Import** > pilih berkas `.sql.gz` (phpMyAdmin menerima gzip; batas ukuran sesuai `upload_max_filesize`).

PostgreSQL: `gunzip -c <berkas>.sql.gz | psql --host=... --username=... --dbname=...` (database kosong).

Berkas privat: `tar -xzf ppti-files-<waktu>.tar.gz -C ~/ppti/backend/shared/storage/app/private`.

### Uji restore (lakukan sebulan sekali)

1. cPanel > MySQL Databases: buat database `akunanda_ppti_uji` + beri hak ke user yang sama.
2. `gunzip -c <backup terbaru>.sql.gz | mysql -u akunanda_ppti -p akunanda_ppti_uji`
3. Bandingkan jumlah baris: `mysql -u akunanda_ppti -p -e "SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM registrations; SELECT COUNT(*) FROM invoices;" akunanda_ppti_uji` dengan database produksi.
4. Hapus database uji. Catat tanggal uji di catatan operasional.

## 11. Pemantauan

| Endpoint | Kegunaan |
|---|---|
| `https://api.pusatpelatihan.id/up` | Liveness Laravel (aplikasi bisa boot). |
| `https://api.pusatpelatihan.id/api/v1/health/ready` | Kesiapan: `database`, `storage` (bisa ditulis), `scheduler` (cron berjalan, detak < 5 menit), `queue` (antrean tidak menumpuk). HTTP **503** bila database, storage, atau scheduler gagal; `warn` untuk antrean menumpuk tetap 200. Tidak memuat versi, nama host, atau data pengguna. |
| `https://pusatpelatihan.id` | Frontend. |

Pasang pemantau gratis (UptimeRobot, Better Stack, dsb.) tiap 5 menit ke ketiga URL, notifikasi ke email/WA admin.

## 12. Pemecahan masalah

| Gejala | Penyebab umum | Perbaikan |
|---|---|---|
| **500** di API/admin, log kosong | Izin folder | `chmod -R u+rwX ~/ppti/backend/shared/storage ~/ppti/backend/current/bootstrap/cache`; folder 755, berkas 644. Cek `~/ppti/backend/shared/storage/logs/laravel-*.log` dan cPanel > **Errors**. |
| 500 setelah mengubah `.env` | Konfigurasi lama ter-cache | `php artisan config:cache` |
| "No application encryption key" | `APP_KEY` kosong | `php artisan key:generate` di folder current, lalu `config:cache`. |
| **502/503** di `pusatpelatihan.id` | Aplikasi Node mati atau gagal start | Setup Node.js App > lihat log (`stderr.log` di Application root) > Restart. Cek versi Node >= 20.9, startup file `server.js`, memori proses cukup. |
| Halaman publik tampil data contoh lama | Frontend tidak bisa menghubungi API | Cek `API_URL_INTERNAL` di `shared/.env.production`, lalu Restart. Uji `curl` ke URL itu dari Terminal. |
| Perubahan di admin tidak muncul di situs | `REVALIDATE_SECRET` beda / `FRONTEND_URL` salah | Samakan secret kedua sisi; `FRONTEND_URL` = URL frontend persis (https). Log backend memuat "Revalidasi frontend gagal". |
| Galat **CORS** di browser | `FRONTEND_URL` tidak mencakup origin (mis. `www`) | Tambah origin dipisah koma, `config:cache`. |
| Login portal gagal / langsung keluar | Cookie token frontend tidak tersimpan | Pastikan frontend diakses lewat HTTPS dan jam server benar. Login admin `/admin` gagal: `SESSION_DOMAIN` harus kosong atau `api.pusatpelatihan.id`, `SESSION_SECURE_COOKIE=true` hanya bila HTTPS aktif. |
| Semua pendaftaran kena "terlalu banyak percobaan" | IP pengunjung tidak diteruskan | `API_PROXY_SECRET` harus sama di kedua sisi. |
| `scheduler: fail` di health check / email notifikasi tidak terkirim | Cron tidak jalan | Cek Cron Jobs (path `php` & folder benar), jalankan perintah cron manual di Terminal dan baca galatnya. |
| Antrean menumpuk (`queue: warn`) | Cron antrean tidak jalan / pekerjaan gagal | Jalankan `php artisan queue:work --stop-when-empty` manual; cek `php artisan queue:failed`. |
| Backup gagal "mysqldump tidak ditemukan" | Program tidak ada di PATH cron | `which mysqldump` di Terminal, isi `BACKUP_MYSQLDUMP_PATH`; atau pakai Backup cPanel. |
| Unggahan > 2 MB gagal | Batas PHP | MultiPHP INI Editor: `upload_max_filesize=25M`, `post_max_size=25M`. |

## 13. Yang belum diuji tanpa server

Diuji otomatis: skrip rilis/rollback di Linux (WSL Ubuntu) dengan stub `php` (`bash scripts/deploy/tests/run.sh`: struktur folder, symlink, urutan langkah, idempoten, migrate gagal tidak mengalihkan rilis, rollback, rotasi, mode tanpa symlink), perintah `backup:database` (dump SQLite sungguhan + pemulihan, rotasi, arsip berkas, argumen mysqldump/pg_dump tanpa sandi), endpoint kesiapan, serta migrasi & tes di MySQL 8.0, MariaDB 10.11, PostgreSQL 16 (CI).

Belum bisa diuji sampai paket hosting tersedia:

- Setup Node.js App/Passenger menjalankan `server.js` dari Application root yang berupa symlink, dan restart lewat `tmp/restart.txt`.
- Apache mengikuti symlink document root `ppti/backend/current/public` (bergantung `SymLinksIfOwnerMatch` hosting).
- `mysqldump` versi hosting terhadap server database hosting, dan batas waktu/memori proses di cron.
- Versi Node/PHP di Terminal vs di web, path `php` untuk cron.
- AutoSSL, Force HTTPS, email SMTP cPanel, batas cron per menit.
- Workflow rilis dan job deploy SSH (job deploy mati; workflow rilis baru berjalan saat pertama kali dijalankan di GitHub).
- Mode `PPTI_NO_SYMLINK=1` di cPanel sungguhan, termasuk `public/storage`.
