<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Label tanggal bahasa Indonesia persis format website lama. */
final class IdDate
{
    /** "17 November 2026" */
    public static function long(?CarbonInterface $d): ?string
    {
        return $d?->copy()->locale('id')->translatedFormat('j F Y');
    }

    /** "17 Nov 2026" */
    public static function short(?CarbonInterface $d): ?string
    {
        return $d?->copy()->locale('id')->translatedFormat('j M Y');
    }

    /** "Batch November" */
    public static function batch(?CarbonInterface $d): ?string
    {
        return $d ? 'Batch '.$d->copy()->locale('id')->translatedFormat('F') : null;
    }
}
