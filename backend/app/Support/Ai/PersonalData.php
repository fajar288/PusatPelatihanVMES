<?php

namespace App\Support\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimisasi data pribadi sebelum teks atau data dikirim ke API AI.
 *
 * - scrub(): mengganti email, nomor telepon Indonesia, dan nomor 16 digit (NIK / nomor kartu) dengan penanda.
 * - pseudonym(): pengganti nama yang stabil (HMAC dengan APP_KEY), tidak bisa dibalik tanpa kunci aplikasi.
 * - assertClean(): pengaman terakhir sebelum panggilan model; melempar galat bila masih ada email/telepon/NIK.
 */
final class PersonalData
{
    public const EMAIL = '[email]';

    public const PHONE = '[telepon]';

    public const ID_NUMBER = '[nomor-identitas]';

    private const EMAIL_RE = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    // 16 digit berturut-turut (NIK, nomor kartu), boleh dipisah spasi/titik/tanda hubung per 4 digit.
    private const ID_RE = '/(?<!\d)\d{4}[ .\-]?\d{4}[ .\-]?\d{4}[ .\-]?\d{4}(?!\d)/';

    // +62 / 62 / 0 diikuti 8, lalu 7-12 digit lagi; boleh dipisah spasi, titik, atau tanda hubung.
    private const PHONE_RE = '/(?<![\d\w])(?:\+?62|0)[ .\-]?8(?:[ .\-]?\d){7,12}(?!\d)/';

    public static function scrub(string $text): string
    {
        $text = (string) preg_replace(self::EMAIL_RE, self::EMAIL, $text);
        $text = (string) preg_replace(self::ID_RE, self::ID_NUMBER, $text);

        return (string) preg_replace(self::PHONE_RE, self::PHONE, $text);
    }

    public static function containsPersonalData(string $text): bool
    {
        return preg_match(self::EMAIL_RE, $text) === 1
            || preg_match(self::ID_RE, $text) === 1
            || preg_match(self::PHONE_RE, $text) === 1;
    }

    /** Pseudonim stabil untuk satu entitas, mis. "P-3FA9C2D1". Sama untuk entitas yang sama, beda antar entitas. */
    public static function pseudonym(Model|string $subject, int|string|null $id = null, string $prefix = 'P'): string
    {
        $key = $subject instanceof Model ? $subject->getMorphClass().':'.$subject->getKey() : $subject.':'.$id;

        return $prefix.'-'.strtoupper(substr(hash_hmac('sha256', $key, (string) config('app.key')), 0, 8));
    }

    /**
     * Pengaman terakhir: semua teks yang akan dikirim ke model tidak boleh lagi memuat email, telepon, atau NIK.
     *
     * @param  array<mixed>|string  $payload
     *
     * @throws PersonalDataLeak
     */
    public static function assertClean(array|string $payload): void
    {
        $texts = is_string($payload) ? [$payload] : [];
        if (is_array($payload)) {
            array_walk_recursive($payload, function ($v) use (&$texts): void {
                if (is_string($v)) {
                    $texts[] = $v;
                }
            });
        }
        foreach ($texts as $t) {
            if (self::containsPersonalData($t)) {
                throw new PersonalDataLeak('Data yang akan dikirim ke model AI masih memuat email, nomor telepon, atau nomor identitas.');
            }
        }
    }
}
