<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;

/**
 * Potongan SQL yang berbeda per database (SQLite untuk tes, PostgreSQL untuk pengembangan, MySQL/MariaDB di hosting
 * cPanel). Semua query yang butuh ekspresi khusus dialek memakai helper ini agar logikanya tidak tersebar.
 *
 * Catatan perilaku: pencarian memakai LOWER() di kedua sisi sehingga tidak membedakan huruf besar/kecil di semua
 * database. Collation bawaan MySQL/MariaDB (utf8mb4_unicode_ci) juga mengabaikan aksen ("e" cocok dengan "é"),
 * sedangkan PostgreSQL & SQLite tidak; teks program/kota di katalog tidak memakai aksen sehingga hasilnya sama.
 */
final class Sql
{
    public static function isMysqlFamily(?string $driver = null): bool
    {
        return in_array($driver ?? DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** Ekspresi kolom sebagai teks (mis. kolom JSON untuk LIKE): CAST AS CHAR di MySQL/MariaDB, CAST AS TEXT lainnya. */
    public static function asText(string $column): string
    {
        return 'CAST('.$column.' AS '.(self::isMysqlFamily() ? 'CHAR' : 'TEXT').')';
    }

    /** Nilai parameter LIKE "mengandung": huruf kecil, dibungkus %. */
    public static function containsValue(string $term): string
    {
        return '%'.mb_strtolower(trim($term)).'%';
    }

    /**
     * WHERE LOWER(COALESCE(kolom, '')) LIKE '%term%' (tanpa membedakan huruf besar/kecil). `$column` boleh berupa
     * ekspresi, mis. Sql::asText('tags').
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function whereContains(BuilderContract $query, string $column, string $term, string $boolean = 'and'): BuilderContract
    {
        return $query->whereRaw('LOWER(COALESCE('.$column.", '')) LIKE ?", [self::containsValue($term)], $boolean);
    }

    /** OR versi whereContains(). */
    public static function orWhereContains(BuilderContract $query, string $column, string $term): BuilderContract
    {
        return self::whereContains($query, $column, $term, 'or');
    }

    /**
     * WHERE LOWER(kolom) = nilai huruf kecil: kesamaan tanpa membedakan huruf besar/kecil (email, nama unit, kota).
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function whereEqualsCi(BuilderContract $query, string $column, string $value): BuilderContract
    {
        return $query->whereRaw('LOWER('.$column.') = ?', [mb_strtolower(trim($value))]);
    }
}
