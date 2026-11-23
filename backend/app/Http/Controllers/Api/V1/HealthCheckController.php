<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /api/v1/health/ready: pemeriksaan kesiapan untuk pemantau ketersediaan (UptimeRobot, Better Stack, dll.).
 *
 * Mengembalikan 200 bila layanan siap dan 503 bila ada pemeriksaan yang gagal. Isinya hanya status per komponen,
 * tanpa versi, nama host, nama database, pesan galat, atau data pengguna.
 *  - database: SELECT 1 berhasil
 *  - storage: folder storage/framework bisa ditulis (cache, sesi, view)
 *  - scheduler: cron `schedule:run` berjalan (detak terakhir < 5 menit; diisi tiap menit oleh scheduler)
 *  - queue: antrean tidak menumpuk (pekerjaan tertua < 10 menit; hanya peringatan, tidak membuat 503)
 */
class HealthCheckController extends Controller
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    /** Detak scheduler dianggap mati bila lebih lama dari ini (detik). */
    public const SCHEDULER_STALE_SECONDS = 300;

    /** Pekerjaan antrean tertua lebih lama dari ini dianggap menumpuk (detik). */
    public const QUEUE_STALE_SECONDS = 600;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'storage' => is_writable(storage_path('framework')) ? 'ok' : 'fail',
            'scheduler' => $this->scheduler(),
            'queue' => $this->queue(),
        ];
        $failed = in_array('fail', $checks, true);
        $status = $failed ? 'fail' : (in_array('warn', $checks, true) ? 'warn' : 'ok');

        return response()->json(['status' => $status, 'checks' => $checks, 'time' => now()->toIso8601String()], $failed ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }

    /** Dipanggil scheduler tiap menit (routes/console.php). */
    public static function beat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->getTimestamp(), now()->addDay());
    }

    private function database(): string
    {
        try {
            DB::select('SELECT 1');

            return 'ok';
        } catch (\Throwable $e) {
            report($e);

            return 'fail';
        }
    }

    private function scheduler(): string
    {
        try {
            $last = Cache::get(self::HEARTBEAT_KEY);
        } catch (\Throwable) {
            return 'fail';
        }
        if ($last === null) {
            return 'unknown';   // belum pernah berjalan (mis. baru dipasang): tidak membuat 503
        }

        return now()->getTimestamp() - (int) $last <= self::SCHEDULER_STALE_SECONDS ? 'ok' : 'fail';
    }

    private function queue(): string
    {
        if (config('queue.default') !== 'database') {
            return 'ok';
        }
        try {
            $table = (string) config('queue.connections.database.table', 'jobs');
            if (! Schema::hasTable($table)) {
                return 'ok';
            }
            $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
        } catch (\Throwable) {
            return 'warn';
        }

        return $oldest !== null && now()->getTimestamp() - (int) $oldest > self::QUEUE_STALE_SECONDS ? 'warn' : 'ok';
    }
}
