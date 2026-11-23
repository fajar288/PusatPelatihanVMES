<?php

namespace Tests\Feature;

use App\Services\DatabaseBackupService;
use App\Support\PrivateFiles;
use Carbon\Carbon;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use PharData;
use Tests\TestCase;

/**
 * backup:database: dump terkompresi, bisa dipulihkan, rotasi harian/mingguan, arsip berkas privat, dan perintah dump
 * MySQL/PostgreSQL yang tidak membawa kata sandi di argumen. Dump sungguhan diuji dengan SQLite (tersedia di CI);
 * mysqldump/pg_dump diuji lewat Process::fake.
 */
class DatabaseBackupTest extends TestCase
{
    private string $dir;

    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backup-'.uniqid());
        $this->dbFile = $this->dir.'-source.sqlite';
        File::ensureDirectoryExists($this->dir);
        touch($this->dbFile);
        config([
            'backup.path' => $this->dir,
            'backup.keep_daily' => 3,
            'backup.keep_weekly' => 2,
            'backup.weekly_day' => 0,
            'database.connections.backup_source' => ['driver' => 'sqlite', 'database' => $this->dbFile, 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        $db = DB::connection('backup_source');
        $db->statement('CREATE TABLE peserta (id INTEGER PRIMARY KEY, nama TEXT NOT NULL)');
        foreach (['Ani', 'Budi', 'Citra'] as $nama) {
            $db->table('peserta')->insert(['nama' => $nama]);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('backup_source');
        File::deleteDirectory($this->dir);
        File::delete($this->dbFile);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sqlite_backup_is_compressed_outside_public_and_restorable(): void
    {
        Carbon::setTestNow('2026-11-24 01:30:00'); // Selasa
        $r = app(DatabaseBackupService::class)->run('backup_source');

        $this->assertFileExists($r['path']);
        $this->assertStringStartsWith($this->dir.DIRECTORY_SEPARATOR.'daily', $r['path']);
        $this->assertStringEndsWith('ppti-db-20261124-013000.sqlite.gz', $r['path']);
        $this->assertNull($r['weekly'], 'bukan hari mingguan');
        $this->assertStringNotContainsString(public_path(), $r['path']);
        $this->assertFileExists($this->dir.'/.htaccess');

        // uji pemulihan: ekstrak lalu buka sebagai database
        $restored = $this->dir.'/restore.sqlite';
        file_put_contents($restored, gzdecode((string) file_get_contents($r['path'])));
        $pdo = new PDO('sqlite:'.$restored);
        $this->assertSame(['Ani', 'Budi', 'Citra'], $pdo->query('SELECT nama FROM peserta ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $pdo = null;
    }

    public function test_rotation_keeps_daily_and_weekly_limits(): void
    {
        $svc = app(DatabaseBackupService::class);
        // 5 hari berturut-turut, mulai Sabtu 2026-11-21; Minggu 22 menjadi mingguan
        foreach (['2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24', '2026-11-25'] as $day) {
            Carbon::setTestNow($day.' 01:30:00');
            $svc->run('backup_source');
        }
        // dua mingguan lama
        File::put($this->dir.'/weekly/ppti-db-20261108-013000.sqlite.gz', 'lama');
        File::put($this->dir.'/weekly/ppti-db-20261115-013000.sqlite.gz', 'lama');
        Carbon::setTestNow('2026-11-29 01:30:00'); // Minggu
        $r = $svc->run('backup_source');
        $this->assertNotNull($r['weekly']);

        $list = $svc->list();
        $this->assertSame(['ppti-db-20261129-013000.sqlite.gz', 'ppti-db-20261125-013000.sqlite.gz', 'ppti-db-20261124-013000.sqlite.gz'], array_map('basename', $list['daily']));
        $this->assertSame(['ppti-db-20261129-013000.sqlite.gz', 'ppti-db-20261122-013000.sqlite.gz'], array_map('basename', $list['weekly']));

        $this->artisan('backup:database', ['--list' => true])->expectsOutputToContain('DAILY (3)')->assertSuccessful();
    }

    public function test_private_files_archive(): void
    {
        $root = $this->dir.'/private-disk';
        File::ensureDirectoryExists($root.'/registrations/1');
        File::put($root.'/registrations/1/ktp.pdf', '%PDF-uji');
        config(['filesystems.disks.'.PrivateFiles::diskName().'.root' => $root]);
        Carbon::setTestNow('2026-11-24 01:30:00');

        $r = app(DatabaseBackupService::class)->run('backup_source', withFiles: true);
        $this->assertFileExists($r['files']);
        // baca lewat .tar hasil dekompresi (membaca isi langsung dari .tar.gz via phar tidak andal di PHP Windows)
        $plain = $this->dir.'/files-check.tar';
        file_put_contents($plain, gzdecode((string) file_get_contents($r['files'])));
        $tar = new PharData($plain);
        $this->assertTrue(isset($tar['registrations/1/ktp.pdf']));
        $this->assertSame('%PDF-uji', $tar['registrations/1/ktp.pdf']->getContent());
        unset($tar);
    }

    public function test_mysql_and_postgres_dumps_keep_password_out_of_arguments(): void
    {
        config([
            'backup.mysqldump' => '/usr/bin/mysqldump',
            'backup.pg_dump' => '/usr/bin/pg_dump',
            'database.connections.uji_mysql' => ['driver' => 'mysql', 'host' => 'db.local', 'port' => 3306, 'database' => 'ppti', 'username' => 'ppti_user', 'password' => 'Rahasia#1'],
            'database.connections.uji_pgsql' => ['driver' => 'pgsql', 'host' => 'db.local', 'port' => 5432, 'database' => 'ppti', 'username' => 'ppti_user', 'password' => 'Rahasia#2'],
        ]);
        Process::fake([
            '*--help*' => Process::result('  --column-statistics  Write ANALYZE TABLE statements'),
            '*' => Process::result("-- dump uji\nCREATE TABLE peserta (id int);\n"),
        ]);
        Carbon::setTestNow('2026-11-24 01:30:00');
        $svc = app(DatabaseBackupService::class);

        $mysql = $svc->run('uji_mysql');
        $this->assertStringContainsString('CREATE TABLE peserta', (string) gzdecode((string) file_get_contents($mysql['path'])));
        Process::assertRan(function (PendingProcess $p) {
            $cmd = implode(' ', (array) $p->command);

            return str_contains($cmd, '/usr/bin/mysqldump') && ! str_contains($cmd, '--help')
                && str_contains($cmd, '--single-transaction') && str_contains($cmd, '--column-statistics=0') && str_contains($cmd, '--user=ppti_user')
                && ! str_contains($cmd, 'Rahasia') && ($p->environment['MYSQL_PWD'] ?? null) === 'Rahasia#1';
        });

        Carbon::setTestNow('2026-11-24 01:31:00');
        $svc->run('uji_pgsql');
        Process::assertRan(function (PendingProcess $p) {
            $cmd = implode(' ', (array) $p->command);

            return str_contains($cmd, '/usr/bin/pg_dump') && str_contains($cmd, '--no-owner') && str_contains($cmd, '--dbname=ppti')
                && ! str_contains($cmd, 'Rahasia') && ($p->environment['PGPASSWORD'] ?? null) === 'Rahasia#2';
        });
    }

    public function test_failed_dump_reports_error_and_leaves_no_partial_file(): void
    {
        config([
            'database.default' => 'uji_mysql',
            'backup.mysqldump' => '/usr/bin/mysqldump',
            'database.connections.uji_mysql' => ['driver' => 'mysql', 'host' => 'db.local', 'port' => 3306, 'database' => 'ppti', 'username' => 'u', 'password' => 'p'],
        ]);
        Process::fake([
            '*--help*' => Process::result(''),
            '*' => Process::result('', "mysqldump: Got error: 1045: Access denied for user 'u'", 2),
        ]);

        $this->artisan('backup:database')->expectsOutputToContain('Access denied')->assertFailed();
        $this->assertSame([], glob($this->dir.'/daily/*.gz'));
    }
}
