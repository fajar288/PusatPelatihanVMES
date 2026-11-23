# Kompatibilitas database

Backend berjalan di empat database dan diuji otomatis di keempatnya pada setiap pull request:

| Database | Dipakai untuk | Diuji di CI |
|---|---|---|
| SQLite (in-memory) | tes cepat | job `Backend (Laravel)` |
| PostgreSQL 16 | pengembangan lokal | job `Backend (PostgreSQL 16)` |
| MySQL 8.0 | hosting cPanel | job `Backend (MySQL 8.0)` |
| MariaDB 10.11 | hosting cPanel | job `Backend (MariaDB 10.11)` |

Job MySQL, MariaDB, dan PostgreSQL menjalankan `migrate:fresh --seed`, lalu `migrate:reset` + `migrate` (semua migrasi bisa di-rollback), lalu seluruh tes. Angka KPI dan dashboard dibandingkan dengan snapshot yang sama (`tests/fixtures/kpi-golden.json`, tes `test_kpi_numbers_match_golden_snapshot_on_every_database`), jadi hasil laporan dijamin identik di keempat database.

## Versi minimum

- **MySQL 8.0.16 atau lebih baru.** CHECK constraint (aturan sponsor enrollment: corporate wajib punya sponsor, mandiri tanpa sponsor) baru ditegakkan sejak 8.0.16. MySQL 5.7 tidak didukung.
- **MariaDB 10.4 atau lebih baru** (diuji di 10.11; disarankan 10.6+ yang masih didukung vendor). CHECK constraint ditegakkan sejak 10.2, tipe JSON adalah alias LONGTEXT.
- **PostgreSQL 13 atau lebih baru** (diuji di 16).
- Engine InnoDB, charset `utf8mb4`, collation `utf8mb4_unicode_ci` (bawaan `config/database.php`).

## Setelan `.env` untuk MySQL/MariaDB (cPanel)

```dotenv
DB_CONNECTION=mysql            # server MariaDB boleh juga memakai mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cpaneluser_ppti    # cPanel menambahkan awalan nama akun
DB_USERNAME=cpaneluser_ppti
DB_PASSWORD=isi-kata-sandi-kuat
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

User database perlu hak **ALL PRIVILEGES** pada databasenya (dipakai migrasi: CREATE, ALTER, INDEX, REFERENCES, termasuk CHECK constraint). Di cPanel: MySQL Databases, Add User To Database, centang All Privileges.

## Aturan menulis query dan migrasi

Semua ekspresi SQL yang berbeda per dialek memakai `App\Support\Sql`:

| Kebutuhan | Pakai | Alasan |
|---|---|---|
| Cari teks tanpa beda huruf besar/kecil | `Sql::whereContains($q, 'kolom', $term)` / `orWhereContains` | `LOWER(...) LIKE` sama di semua database; jangan memakai `ILIKE` (khusus PostgreSQL) |
| Kesamaan tanpa beda huruf besar/kecil (email, kota, unit) | `Sql::whereEqualsCi($q, 'email', $value)` | |
| Kolom JSON sebagai teks (mis. untuk LIKE) | `Sql::asText('kolom')` | `CAST(x AS TEXT)` tidak dikenal MySQL/MariaDB (harus `CHAR`) |
| Bandingkan nilai JSON tersimpan | `App\Support\JsonValue::same($a, $b)` | tipe JSON MySQL mengurutkan ulang kunci objek |

Hal lain yang harus dijaga:

- **Nama indeks/constraint maksimal 64 karakter** (MySQL/MariaDB menolak, PostgreSQL memotong di 63). Indeks gabungan dengan nama otomatis yang panjang diberi nama eksplisit.
- **Indeks yang diawali kolom foreign key**: di InnoDB indeks itu bisa menjadi indeks foreign key. Saat rollback, hapus foreign key dulu, baru indeksnya.
- **Kolom JSON di tabel yang dipilih lewat multi-select relasi Filament** memakai `jsonb` di PostgreSQL (migrasi `2026_11_23_110000`). Filament menjalankan `SELECT DISTINCT tabel.*`, dan tipe `json` PostgreSQL tidak bisa dibandingkan. Tambahkan nama tabel ke migrasi itu (atau pakai `jsonb`) bila ada multi-select relasi baru ke tabel ber-kolom JSON.
- **Insert yang sengaja boleh gagal karena unique** (mis. kunci idempotensi) dibungkus `DB::transaction()` agar menjadi savepoint. Di PostgreSQL, query yang gagal membatalkan seluruh transaksi yang sedang berjalan.
- **Urutan daftar teks** yang ditampilkan atau dibandingkan tes diurutkan di PHP, bukan `ORDER BY` kolom teks. Collation tiap database memperlakukan huruf besar/kecil dan spasi berbeda.
- **Pengelompokan per bulan/tanggal** dilakukan di PHP (Carbon), bukan dengan `DATE_FORMAT`/`to_char`/`strftime`. Saat ini tidak ada query yang memakai fungsi tanggal khusus dialek.
- **Uang** memakai `decimal(14, 2)`, dihitung di PHP dan dibandingkan sebagai angka (driver bisa mengembalikan string seperti `"12500.00"`).
- **TIMESTAMP MySQL** berlaku sampai tahun 2038; tanggal masa depan yang jauh (mis. masa berlaku) memakai kolom `date`.

## Perbedaan perilaku yang diketahui (tidak memengaruhi fitur)

- **Urutan kunci objek JSON**: MySQL menyimpan kunci objek dalam urutan sendiri (kunci pendek dulu). Isinya tetap sama dan frontend membaca per kunci, jadi tampilan tidak berubah. Pemeriksaan "ada perubahan atau tidak" (editor pengaturan CMS, audit) memakai bentuk kanonik.
- **Aksen dalam pencarian**: collation `utf8mb4_unicode_ci` mengabaikan aksen ("e" cocok dengan "é"), PostgreSQL dan SQLite tidak. Judul program, kota, dan kategori tidak memakai aksen sehingga hasil pencarian sama.
- **Urutan teks di daftar admin** (`ORDER BY` judul/nama) bisa berbeda untuk nama yang hanya beda huruf besar/kecil. Data dan angka laporan tidak terpengaruh.
- **Zona waktu**: aplikasi menulis waktu dalam `APP_TIMEZONE` (Asia/Jakarta). Hanya kolom log dengan nilai bawaan database (`useCurrent`) yang memakai jam server database, dan perilakunya sama di semua database.

## Menjalankan tes di MySQL/MariaDB/PostgreSQL secara lokal

```bash
# contoh MySQL lewat Docker
docker run -d --name ppti-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=ppti_test -p 3306:3306 mysql:8.0
cd backend
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=ppti_test DB_USERNAME=root DB_PASSWORD=root php artisan migrate:fresh --seed
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=ppti_test DB_USERNAME=root DB_PASSWORD=root php artisan test
```

Variabel `DB_*` dari lingkungan menimpa nilai bawaan di `phpunit.xml` (SQLite in-memory).
