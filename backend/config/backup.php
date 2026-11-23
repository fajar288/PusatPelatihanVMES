<?php

/*
| Backup database terjadwal (`php artisan backup:database`, lihat docs/deploy-cpanel.md bagian Backup).
| Dump terkompresi (.sql.gz untuk MySQL/MariaDB/PostgreSQL, .sqlite.gz untuk SQLite) disimpan di folder di luar
| document root, lalu dirotasi: simpan N backup harian terbaru dan N backup mingguan (dibuat hari Minggu).
| Penyalinan ke lokasi terpisah (offsite) belum diatur karena penyedia penyimpanan belum diputuskan.
*/
return [
    // Jalankan otomatis lewat scheduler (cron `schedule:run`). Bawaan mati agar mesin pengembangan tanpa mysqldump/pg_dump
    // tidak mencatat galat tiap malam; nyalakan di .env produksi (BACKUP_ENABLED=true).
    'enabled' => (bool) env('BACKUP_ENABLED', false),

    // Jam backup harian (zona APP_TIMEZONE).
    'time' => env('BACKUP_TIME', '01:30'),

    // Folder tujuan. Bawaan storage/app/backups: di luar public/ dan tidak bisa diakses lewat web.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Rotasi
    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 7),
    'keep_weekly' => (int) env('BACKUP_KEEP_WEEKLY', 4),

    // Hari dibuatnya backup mingguan (0 = Minggu ... 6 = Sabtu).
    'weekly_day' => (int) env('BACKUP_WEEKLY_DAY', 0),

    // Ikut mengarsipkan berkas privat (storage/app/private: dokumen pendaftaran, bukti bayar, tugas, foto profil)
    // ke files-*.tar.gz pada backup yang sama. Bisa juga memakai fitur Backup cPanel (Home Directory).
    'include_private_files' => (bool) env('BACKUP_INCLUDE_PRIVATE_FILES', false),

    // Lokasi program dump bila tidak ada di PATH (mis. /usr/bin/mysqldump). Kosong = cari di PATH.
    'mysqldump' => env('BACKUP_MYSQLDUMP_PATH'),
    'pg_dump' => env('BACKUP_PG_DUMP_PATH'),

    // Batas waktu proses dump (detik).
    'timeout' => (int) env('BACKUP_TIMEOUT', 900),
];
