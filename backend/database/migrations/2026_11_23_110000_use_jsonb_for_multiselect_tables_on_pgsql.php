<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Khusus PostgreSQL: kolom `json` di tabel yang dipilih lewat multi-select relasi Filament (paket training untuk
 * "Program prasyarat", trainer untuk "Trainer / assessor") diubah ke `jsonb`.
 *
 * Filament selalu menjalankan `SELECT DISTINCT tabel.*` untuk pilihan relasi BelongsToMany, dan tipe `json` di
 * PostgreSQL tidak punya operator kesamaan sehingga query itu gagal ("could not identify an equality operator for
 * type json"). `jsonb` punya operator kesamaan. Isi kolom ini berupa daftar teks atau objek aturan kecil yang dibaca
 * per kunci, jadi normalisasi jsonb (urutan kunci objek) tidak mengubah perilaku. MySQL (JSON), MariaDB (LONGTEXT),
 * dan SQLite (TEXT) sudah bisa DISTINCT sehingga tidak diubah.
 */
return new class extends Migration
{
    private const TABLES = ['training_packages', 'trainers'];

    public function up(): void
    {
        $this->convert('json', 'jsonb');
    }

    public function down(): void
    {
        $this->convert('jsonb', 'json');
    }

    private function convert(string $from, string $to): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::TABLES as $table) {
            foreach (Schema::getColumns($table) as $column) {
                if (($column['type_name'] ?? null) === $from) {
                    DB::statement(sprintf('ALTER TABLE "%s" ALTER COLUMN "%s" TYPE %s USING "%s"::%s', $table, $column['name'], $to, $column['name'], $to));
                }
            }
        }
    }
};
