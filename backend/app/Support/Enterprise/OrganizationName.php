<?php

namespace App\Support\Enterprise;

use Illuminate\Support\Str;

/** Normalisasi nama perusahaan untuk pencocokan: "PT. Abc Tbk" ≈ "ABC" ≈ "abc, pt". */
final class OrganizationName
{
    private const LEGAL_FORMS = ['pt', 'cv', 'tbk', 'persero', 'perseroan', 'terbatas', 'ud', 'pd', 'koperasi', 'yayasan', 'ltd', 'inc', 'co', 'corp', 'llc'];

    public static function normalize(?string $name): string
    {
        $s = Str::of((string) $name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
        $words = array_values(array_filter(explode(' ', $s), fn (string $w) => $w !== '' && ! in_array($w, self::LEGAL_FORMS, true)));

        return implode(' ', $words);
    }
}
