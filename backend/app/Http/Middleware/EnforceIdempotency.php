<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `api.idempotent` (wajib) / `api.idempotent:optional` — header `Idempotency-Key` untuk POST penting (PRD Roadmap 5 §7.1).
 *
 * - Key + isi sama dalam 24 jam → respons sukses pertama diputar ulang (header `Idempotent-Replayed: true`), aksi tidak diulang.
 * - Key sama, isi berbeda → 422 `idempotency_key_reused`.
 * - Permintaan pertama masih diproses → 409 `idempotency_in_progress` (coba lagi sebentar).
 * - Hanya respons 2xx yang disimpan; galat (validasi, aturan bisnis, 5xx) melepas key agar bisa dicoba ulang setelah diperbaiki.
 */
class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $client = $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);
        $key = $request->header(self::HEADER);

        if (! $client instanceof ApiClient || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        if ($key === null || $key === '') {
            return $mode === 'optional'
                ? $next($request)
                : AuthenticateApiClient::error('idempotency_key_required', 'Header Idempotency-Key wajib untuk permintaan ini (mis. UUID v4 unik per transaksi).', 400);
        }
        if (strlen($key) > 255 || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $key)) {
            return AuthenticateApiClient::error('idempotency_key_invalid', 'Idempotency-Key maks. 255 karakter: huruf, angka, titik, titik dua, garis bawah atau tanda hubung.', 400);
        }

        $hash = self::fingerprint($request);
        $record = $this->reserve($client, $key, $hash, $request);
        if ($record instanceof Response) {
            return $record;
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();
            throw $e;
        }

        if ($response->isSuccessful()) {
            $record->forceFill(['status' => 'completed', 'response_status' => $response->getStatusCode(), 'response_body' => (string) $response->getContent()])->save();
        } else {
            $record->delete();
        }

        return $response;
    }

    /** Pesan key (insert atomik — unique per klien) atau kembalikan respons replay/konflik. */
    private function reserve(ApiClient $client, string $key, string $hash, Request $request): ApiIdempotencyKey|Response
    {
        for ($i = 0; $i < 2; $i++) {
            try {
                return ApiIdempotencyKey::query()->create([
                    'api_client_id' => $client->id, 'key' => $key, 'method' => $request->method(), 'route' => $request->route()?->getName(),
                    'request_hash' => $hash, 'status' => 'processing', 'expires_at' => now()->addHours(ApiIdempotencyKey::TTL_HOURS),
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = ApiIdempotencyKey::query()->where('api_client_id', $client->id)->where('key', $key)->first();
                if (! $existing) {
                    continue;   // baru saja dilepas proses lain → coba pesan lagi
                }
                if ($existing->isExpired()) {
                    $existing->delete();

                    continue;
                }
                if (! hash_equals($existing->request_hash, $hash)) {
                    return AuthenticateApiClient::error('idempotency_key_reused', 'Idempotency-Key ini sudah dipakai untuk permintaan dengan isi atau endpoint berbeda. Gunakan key baru.', 422);
                }
                if ($existing->status !== 'completed') {
                    return AuthenticateApiClient::error('idempotency_in_progress', 'Permintaan dengan Idempotency-Key ini masih diproses. Coba lagi beberapa saat.', 409)
                        ->withHeaders(['Retry-After' => 2]);
                }

                return response((string) $existing->response_body, (int) $existing->response_status, [
                    'Content-Type' => 'application/json',
                    'Idempotent-Replayed' => 'true',
                ]);
            }
        }

        return AuthenticateApiClient::error('idempotency_in_progress', 'Permintaan dengan Idempotency-Key ini masih diproses. Coba lagi beberapa saat.', 409);
    }

    /** sha256 dari method + path + isi JSON yang dinormalkan (urutan kunci objek tidak berpengaruh). */
    public static function fingerprint(Request $request): string
    {
        $raw = (string) $request->getContent();
        $decoded = json_decode($raw, true);
        $body = is_array($decoded) ? json_encode(self::sortKeys($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $raw;

        return hash('sha256', $request->method().' '.$request->path()."\n".$body);
    }

    private static function sortKeys(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        return array_map(fn ($v) => is_array($v) ? self::sortKeys($v) : $v, $data);
    }
}
