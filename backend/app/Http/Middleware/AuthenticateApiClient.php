<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi API Publik: header `X-Api-Key: ppti_...` (atau `Authorization: Bearer ppti_...`).
 * Memeriksa status aktif, masa berlaku & daftar IP, menerapkan rate limit per klien (header X-RateLimit-*),
 * lalu mencatat setiap permintaan ke `api_request_logs` setelah respons terkirim.
 */
class AuthenticateApiClient
{
    public const ATTRIBUTE = 'api_client';

    /** Versi kontrak API Publik (path /api/public/v1 + header respons API-Version). */
    public const VERSION = '1';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api_started_at', microtime(true));
        $key = $request->header('X-Api-Key') ?: $request->bearerToken();
        $client = ApiClient::findByKey($key);

        if (! $client) {
            return self::error('unauthenticated', 'API key tidak ada atau tidak valid. Kirim header X-Api-Key.', 401);
        }
        $request->attributes->set(self::ATTRIBUTE, $client);

        if (! $client->is_active) {
            return self::error('client_disabled', 'Klien API ini dinonaktifkan. Hubungi PPTI.', 403);
        }
        if ($client->isExpired()) {
            return self::error('key_expired', 'API key sudah kedaluwarsa.', 401);
        }
        if (! $client->allowsIp($request->ip())) {
            return self::error('ip_not_allowed', 'Alamat IP '.$request->ip().' tidak diizinkan untuk klien ini.', 403);
        }

        $limit = max(1, (int) ($client->rate_limit ?: config('services.public_api.default_rate_limit', 60)));
        $bucket = 'public-api:'.$client->id;
        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            $retry = RateLimiter::availableIn($bucket);

            return self::error('rate_limited', "Batas $limit permintaan per menit terlampaui. Coba lagi dalam $retry detik.", 429)
                ->withHeaders(['Retry-After' => $retry, 'X-RateLimit-Limit' => $limit, 'X-RateLimit-Remaining' => 0]);
        }
        RateLimiter::hit($bucket, 60);

        if (! $client->last_used_at || $client->last_used_at->lt(now()->subMinute()) || $client->last_used_ip !== $request->ip()) {
            $client->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('API-Version', self::VERSION);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($bucket, $limit)));

        return $response;
    }

    /** Catat permintaan terautentikasi (termasuk yang ditolak scope/IP/limit) setelah respons dikirim. */
    public function terminate(Request $request, Response $response): void
    {
        $client = $request->attributes->get(self::ATTRIBUTE);
        if (! $client instanceof ApiClient) {
            return;
        }
        $started = (float) $request->attributes->get('api_started_at', microtime(true));
        ApiRequestLog::query()->create([
            'api_client_id' => $client->id,
            'method' => $request->method(),
            'path' => Str::limit('/'.ltrim($request->path(), '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 250, ''),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);
    }

    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
