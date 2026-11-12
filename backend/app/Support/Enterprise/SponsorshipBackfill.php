<?php

namespace App\Support\Enterprise;

use Illuminate\Support\Facades\DB;

/**
 * Backfill sponsorship untuk data lama (Roadmap 5 5A, dipanggil migrasi add_sponsorship_to_enrollments):
 * enrollment individu tetap SELF; enrollment dari pendaftaran kolektif → CORPORATE dengan organisasi hasil normalisasi
 * nama perusahaan (needs_review = true). Memakai query builder (bukan model) agar aman dijalankan dari migrasi. Idempoten.
 */
final class SponsorshipBackfill
{
    /** @return array{organizations:int, registrations:int, enrollments:int} */
    public static function run(): array
    {
        $stats = ['organizations' => 0, 'registrations' => 0, 'enrollments' => 0];
        $regs = DB::table('registrations')->where('type', 'corporate')->whereNull('organization_id')->orderBy('id')->get(['id', 'registration_number', 'company']);
        $orgByName = [];

        foreach ($regs as $reg) {
            $legal = trim((string) $reg->company) !== '' ? trim((string) $reg->company) : "Perusahaan tanpa nama ({$reg->registration_number})";
            $norm = OrganizationName::normalize($legal) ?: 'reg-'.$reg->id;
            if (! isset($orgByName[$norm])) {
                $existing = DB::table('organizations')->where('normalized_name', $norm)->orderBy('id')->value('id');
                if (! $existing) {
                    $existing = DB::table('organizations')->insertGetId([
                        'code' => self::nextCode(),
                        'legal_name' => $legal,
                        'normalized_name' => $norm,
                        'status' => 'active',
                        'needs_review' => true,
                        'source' => 'backfill',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $stats['organizations']++;
                }
                $orgByName[$norm] = $existing;
            }
            DB::table('registrations')->where('id', $reg->id)->update(['organization_id' => $orgByName[$norm]]);
            $stats['registrations']++;
            $stats['enrollments'] += DB::table('enrollments')->where('registration_id', $reg->id)->where('type', 'self')
                ->update(['type' => 'corporate', 'sponsor_organization_id' => $orgByName[$norm]]);
        }

        return $stats;
    }

    private static function nextCode(): string
    {
        $seq = (int) DB::table('organizations')->count() + 1;
        while (DB::table('organizations')->where('code', $code = 'ORG-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT))->exists()) {
            $seq++;
        }

        return $code;
    }
}
