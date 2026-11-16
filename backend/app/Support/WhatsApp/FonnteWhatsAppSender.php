<?php

namespace App\Support\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Driver `fonnte`: WhatsApp API gateway Fonnte (https://docs.fonnte.com).
 * - Kirim: POST {base}/send, header `Authorization: <token>` (tanpa "Bearer"), body form-encoded.
 * - Profil device: POST {base}/device (status, paket, kuota, kedaluwarsa) — dipakai widget admin.
 * Galat sementara → RuntimeException (di-retry dispatcher); galat permanen → PermanentWhatsAppFailure (langsung gagal).
 * Token tidak pernah ditulis ke log maupun pesan exception.
 */
class FonnteWhatsAppSender implements WhatsAppSender
{
    /** Alasan `status:false` yang tidak akan berhasil walau dicoba ulang. */
    public const PERMANENT_REASONS = [
        'token invalid',
        'devices must belong to an account',
        'target invalid',
        'input invalid',
        'insufficient quota',
    ];

    /** Batas panjang pesan Fonnte. */
    public const MAX_MESSAGE_LENGTH = 60000;

    public const DEVICE_CACHE_KEY = 'fonnte:device-profile';

    public function enabled(): bool
    {
        return (bool) config('notifications.whatsapp.enabled') && $this->configured();
    }

    /** Token Fonnte terisi (boleh dipakai untuk uji kirim/profil device meski kanal belum diaktifkan). */
    public function configured(): bool
    {
        return $this->token() !== '';
    }

    public function provider(): string
    {
        return 'fonnte';
    }

    public function send(string $to, string $text): string
    {
        $json = $this->post('/send', [
            'target' => $to,
            'message' => mb_substr($text, 0, self::MAX_MESSAGE_LENGTH),
            // countryCode WAJIB string: integer membuat Fonnte membalas status:false
            'countryCode' => (string) config('notifications.whatsapp.country_code', '62'),
            // device terputus → langsung gagal (di-retry dispatcher), bukan menumpuk di antrean Fonnte
            'connectOnly' => 'true',
        ]);
        $id = $json['id'][0] ?? null;
        if (! is_scalar($id) || (string) $id === '') {
            throw new RuntimeException('Fonnte: respons sukses tanpa ID pesan.');
        }

        return (string) $id;
    }

    /**
     * Profil device (di-cache 5 menit).
     *
     * @return array{device: ?string, status: ?string, name: ?string, package: ?string, quota: ?string, expired: ?string, messages: ?int}
     *
     * @throws RuntimeException bila token kosong / API gagal
     */
    public function deviceProfile(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::DEVICE_CACHE_KEY);
        }

        return Cache::remember(self::DEVICE_CACHE_KEY, now()->addMinutes(5), function (): array {
            $json = $this->post('/device', []);
            $s = fn (string $k) => isset($json[$k]) && is_scalar($json[$k]) && (string) $json[$k] !== '' ? (string) $json[$k] : null;

            return [
                'device' => $s('device'),
                'status' => $s('device_status'),
                'name' => $s('name'),
                'package' => $s('package'),
                'quota' => $s('quota'),
                'expired' => $s('expired'),
                'messages' => isset($json['messages']) && is_numeric($json['messages']) ? (int) $json['messages'] : null,
            ];
        });
    }

    /**
     * POST form-encoded ke Fonnte dan kembalikan JSON yang berstatus sukses.
     *
     * @param  array<string, string>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        if (! $this->configured()) {
            throw new PermanentWhatsAppFailure('Fonnte: FONNTE_TOKEN belum diisi.');
        }
        try {
            $response = $this->client()->post($path, $body);
        } catch (ConnectionException) {
            throw new RuntimeException('Fonnte: koneksi gagal atau timeout.');
        } catch (Throwable $e) {
            throw new RuntimeException('Fonnte: permintaan gagal ('.class_basename($e).').');
        }

        return $this->parse($response);
    }

    /** @return array<string, mixed> */
    private function parse(Response $response): array
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];
        // contoh "token invalid" di dokumentasi memakai key "Status" (S besar)
        $status = $json['status'] ?? $json['Status'] ?? null;
        $ok = $status === true || (is_string($status) && strtolower($status) === 'true');

        if ($ok && $response->successful()) {
            return $json;
        }
        $reason = isset($json['reason']) && is_scalar($json['reason']) ? trim((string) $json['reason']) : '';
        if ($status !== null && ! $ok && $reason !== '') {
            $message = 'Fonnte: '.Str::limit($this->redact($reason), 300, '');
            if (in_array(strtolower($reason), self::PERMANENT_REASONS, true)) {
                throw new PermanentWhatsAppFailure($message);
            }

            throw new RuntimeException($message);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Fonnte: HTTP '.$response->status().'.');
        }

        throw new RuntimeException('Fonnte: respons tidak dikenali.');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('notifications.whatsapp.fonnte.base_url', 'https://api.fonnte.com'), '/'))
            ->asForm()
            ->acceptJson()
            ->withHeaders(['Authorization' => $this->token()])   // Fonnte: token polos, TANPA "Bearer"
            ->connectTimeout(5)
            ->timeout(max(1, (int) config('notifications.whatsapp.fonnte.timeout', 15)));
    }

    private function token(): string
    {
        return trim((string) config('notifications.whatsapp.fonnte.token'));
    }

    /** Jaga-jaga: buang token bila penyedia menggemakannya di `reason`. */
    private function redact(string $text): string
    {
        $token = $this->token();

        return $token !== '' ? str_replace($token, '[token]', $text) : $text;
    }
}
