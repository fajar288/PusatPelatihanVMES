<?php

namespace App\Services;

use App\Support\PrivateFiles;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PharData;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Backup database terkompresi + rotasi (config/backup.php).
 *
 *  - MySQL/MariaDB: mysqldump/mariadb-dump --single-transaction (snapshot konsisten tanpa mengunci tabel InnoDB).
 *    Kata sandi lewat variabel lingkungan MYSQL_PWD, tidak pernah di argumen (tidak terlihat di daftar proses).
 *  - PostgreSQL: pg_dump format teks, tanpa owner/privilege agar bisa dipulihkan ke user database lain. Kata sandi
 *    lewat PGPASSWORD.
 *  - SQLite: VACUUM INTO (salinan konsisten) lalu dikompres.
 *
 * Hasil: {path}/daily/ppti-db-YYYYmmdd-HHMMSS.sql.gz; pada hari mingguan disalin juga ke {path}/weekly/. Rotasi
 * menyimpan `keep_daily` harian dan `keep_weekly` mingguan terbaru. Opsi berkas privat menulis files-*.tar.gz.
 */
class DatabaseBackupService
{
    /**
     * @return array{path: string, size: int, weekly: ?string, files: ?string, pruned: list<string>}
     */
    public function run(?string $connection = null, bool $withFiles = false, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $connection ??= (string) config('database.default');
        $root = rtrim((string) config('backup.path'), '/\\');
        $daily = $root.DIRECTORY_SEPARATOR.'daily';
        $weekly = $root.DIRECTORY_SEPARATOR.'weekly';
        File::ensureDirectoryExists($daily, 0750);
        File::ensureDirectoryExists($weekly, 0750);
        $this->protect($root);

        $driver = (string) config("database.connections.$connection.driver");
        $stamp = $now->format('Ymd-His');
        $target = $daily.DIRECTORY_SEPARATOR.'ppti-db-'.$stamp.($driver === 'sqlite' ? '.sqlite.gz' : '.sql.gz');

        try {
            match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysql($connection, $driver, $target),
                'pgsql' => $this->dumpPgsql($connection, $target),
                'sqlite' => $this->dumpSqlite($connection, $target),
                default => throw new RuntimeException("Driver database \"$driver\" belum didukung backup."),
            };
        } catch (\Throwable $e) {
            File::delete($target);
            throw $e;
        }
        if (! is_file($target) || filesize($target) < 20) {
            File::delete($target);
            throw new RuntimeException('Backup kosong: periksa hak akses user database dan program dump.');
        }

        $files = null;
        if ($withFiles) {
            $files = $daily.DIRECTORY_SEPARATOR.'ppti-files-'.$stamp.'.tar.gz';
            $this->archivePrivateFiles($files);
        }

        $weeklyCopy = null;
        if ((int) $now->dayOfWeek === (int) config('backup.weekly_day', 0)) {
            $weeklyCopy = $weekly.DIRECTORY_SEPARATOR.basename($target);
            File::copy($target, $weeklyCopy);
            if ($files) {
                File::copy($files, $weekly.DIRECTORY_SEPARATOR.basename($files));
            }
        }

        $pruned = [
            ...$this->prune($daily, (int) config('backup.keep_daily', 7)),
            ...$this->prune($weekly, (int) config('backup.keep_weekly', 4)),
        ];

        return ['path' => $target, 'size' => (int) filesize($target), 'weekly' => $weeklyCopy, 'files' => $files, 'pruned' => $pruned];
    }

    /** Daftar backup yang tersimpan (terbaru dulu). @return array{daily: list<string>, weekly: list<string>} */
    public function list(): array
    {
        $root = rtrim((string) config('backup.path'), '/\\');

        return [
            'daily' => $this->files($root.DIRECTORY_SEPARATOR.'daily'),
            'weekly' => $this->files($root.DIRECTORY_SEPARATOR.'weekly'),
        ];
    }

    private function dumpMysql(string $connection, string $driver, string $target): void
    {
        $c = config("database.connections.$connection");
        $binary = $this->binary(config('backup.mysqldump'), $driver === 'mariadb' ? ['mariadb-dump', 'mysqldump'] : ['mysqldump', 'mariadb-dump']);
        $args = [$binary, '--single-transaction', '--quick', '--no-tablespaces', '--skip-lock-tables', '--default-character-set=utf8mb4',
            '--host='.($c['host'] ?? '127.0.0.1'), '--port='.($c['port'] ?? 3306), '--user='.($c['username'] ?? 'root')];
        if (! empty($c['unix_socket'])) {
            $args[] = '--socket='.$c['unix_socket'];
        }
        // mysqldump 8 meminta information_schema.COLUMN_STATISTICS yang tidak ada di MariaDB/MySQL lama
        if (str_contains($this->help($binary), 'column-statistics')) {
            $args[] = '--column-statistics=0';
        }
        $args[] = (string) $c['database'];

        $this->stream($args, ['MYSQL_PWD' => (string) ($c['password'] ?? '')], $target);
    }

    private function dumpPgsql(string $connection, string $target): void
    {
        $c = config("database.connections.$connection");
        $binary = $this->binary(config('backup.pg_dump'), ['pg_dump']);
        $args = [$binary, '--no-owner', '--no-privileges', '--format=plain', '--encoding=UTF8',
            '--host='.($c['host'] ?? '127.0.0.1'), '--port='.($c['port'] ?? 5432), '--username='.($c['username'] ?? 'postgres'),
            '--dbname='.$c['database']];

        $this->stream($args, ['PGPASSWORD' => (string) ($c['password'] ?? '')], $target);
    }

    private function dumpSqlite(string $connection, string $target): void
    {
        $database = (string) config("database.connections.$connection.database");
        $tmp = $target.'.tmp';
        File::delete($tmp);
        if ($database !== ':memory:' && is_file($database)) {
            try {
                // salinan konsisten walau ada penulisan bersamaan (SQLite >= 3.27)
                DB::connection($connection)->statement('VACUUM INTO ?', [$tmp]);
            } catch (\Throwable) {
                File::copy($database, $tmp);
            }
        } else {
            DB::connection($connection)->statement('VACUUM INTO ?', [$tmp]);
        }
        $this->gzipFile($tmp, $target);
        File::delete($tmp);
    }

    /** Jalankan program dump dan tulis stdout langsung ke berkas .gz (tidak menampung seluruh dump di memori). */
    private function stream(array $command, array $env, string $target): void
    {
        $gz = gzopen($target, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Tidak bisa menulis berkas backup: '.$target);
        }
        $errors = '';
        $written = 0;
        try {
            $result = Process::timeout((int) config('backup.timeout', 900))->env($env)
                ->run($command, function (string $type, string $output) use ($gz, &$errors, &$written) {
                    if ($type === 'out') {
                        $written += (int) gzwrite($gz, $output);
                    } else {
                        $errors .= $output;
                    }
                });
            // tanpa callback (mis. proses palsu di tes) keluaran hanya ada di buffer hasil
            if ($written === 0 && $result->successful() && $result->output() !== '') {
                gzwrite($gz, $result->output());
            }
        } finally {
            gzclose($gz);
        }
        if ($result->failed()) {
            throw new RuntimeException(basename($command[0]).' gagal (kode '.$result->exitCode().'): '.trim(mb_substr($errors ?: $result->errorOutput(), 0, 500)));
        }
    }

    private function gzipFile(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');
        if ($in === false || $out === false) {
            throw new RuntimeException('Tidak bisa mengompres backup.');
        }
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 512));
        }
        fclose($in);
        gzclose($out);
    }

    /** tar.gz isi disk privat (dokumen pendaftaran, bukti bayar, tugas, foto profil). */
    private function archivePrivateFiles(string $target): void
    {
        $dir = (string) config('filesystems.disks.'.PrivateFiles::diskName().'.root');
        if (! is_dir($dir)) {
            throw new RuntimeException('Folder berkas privat tidak ditemukan: '.$dir);
        }
        $tar = substr($target, 0, -3); // .tar
        File::delete([$tar, $target]);
        $phar = new PharData($tar);
        $phar->buildFromDirectory($dir);
        $phar->compress(\Phar::GZ);
        unset($phar);
        File::delete($tar);
        if (! is_file($target)) {
            throw new RuntimeException('Arsip berkas privat gagal dibuat.');
        }
    }

    /** @return list<string> berkas yang dihapus */
    private function prune(string $dir, int $keep): array
    {
        $files = $this->files($dir);
        $groups = [];
        foreach ($files as $f) {
            // db dan files dirotasi terpisah
            $groups[str_contains(basename($f), 'ppti-files-') ? 'files' : 'db'][] = $f;
        }
        $deleted = [];
        foreach ($groups as $list) {
            foreach (array_slice($list, max(0, $keep)) as $old) {
                File::delete($old);
                $deleted[] = $old;
            }
        }

        return $deleted;
    }

    /** @return list<string> berkas backup di folder, terbaru dulu (nama memuat waktu sehingga urut nama = urut waktu) */
    private function files(string $dir): array
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'ppti-*.gz') ?: [];
        rsort($files, SORT_STRING);

        return array_values($files);
    }

    /** Folder backup tidak boleh dibuka lewat web walau salah ditaruh di bawah document root. */
    private function protect(string $root): void
    {
        $ht = $root.DIRECTORY_SEPARATOR.'.htaccess';
        if (! is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
    }

    /** @param list<string> $candidates */
    private function binary(?string $configured, array $candidates): string
    {
        if (filled($configured)) {
            return (string) $configured;
        }
        $finder = new ExecutableFinder;
        foreach ($candidates as $name) {
            if ($path = $finder->find($name)) {
                return $path;
            }
        }
        throw new RuntimeException('Program '.implode(' / ', $candidates).' tidak ditemukan. Isi BACKUP_MYSQLDUMP_PATH / BACKUP_PG_DUMP_PATH atau pakai fitur Backup cPanel.');
    }

    private function help(string $binary): string
    {
        try {
            return (string) Process::timeout(20)->run([$binary, '--help'])->output();
        } catch (\Throwable) {
            return '';
        }
    }
}
