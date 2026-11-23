<?php

namespace Tests\Concerns;

use App\Support\JsonValue;
use Illuminate\Support\Facades\DB;

/**
 * Pembanding nilai kolom JSON yang dibaca kembali dari database. SQLite, PostgreSQL (json), dan MariaDB menyimpan
 * urutan kunci objek apa adanya, jadi di sana perbandingan tetap persis (urutan ikut diuji). Tipe JSON MySQL
 * mengurutkan ulang kunci objek, jadi khusus MySQL dibandingkan dalam bentuk kanonik (isi & tipe sama, urutan
 * kunci objek diabaikan; urutan daftar tetap diuji).
 */
trait AssertsJsonColumns
{
    protected function jsonKeepsKeyOrder(): bool
    {
        return DB::getDriverName() !== 'mysql';
    }

    protected function assertJsonColumn(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($this->jsonKeepsKeyOrder()) {
            $this->assertSame($expected, $actual, $message);

            return;
        }
        $this->assertSame(JsonValue::canonical($expected), JsonValue::canonical($actual), $message);
    }

    /** @param list<string> $expected @param array<string, mixed> $actual */
    protected function assertJsonKeys(array $expected, array $actual, string $message = ''): void
    {
        $keys = array_keys($actual);
        if (! $this->jsonKeepsKeyOrder()) {
            sort($expected);
            sort($keys);
        }
        $this->assertSame($expected, $keys, $message);
    }
}
