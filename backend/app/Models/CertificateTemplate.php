<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Template sertifikat (PRD §5.10): judul, kalimat, penerbit, penandatangan, masa berlaku, format nomor. */
class CertificateTemplate extends Model
{
    public const TYPES = ['participation' => 'Sertifikat keikutsertaan', 'competency' => 'Sertifikat kompetensi', 'vendor' => 'Sertifikasi vendor'];

    /** Kode jenis pada nomor sertifikat. */
    public const TYPE_CODES = ['participation' => 'PST', 'competency' => 'KMP', 'vendor' => 'VND'];

    public const DEFAULT_BODY = [
        'participation' => 'telah mengikuti pelatihan {program} yang diselenggarakan oleh {issuer} pada {start_date} s.d. {end_date} di {city}.',
        'competency' => 'telah dinyatakan KOMPETEN pada program {program} setelah mengikuti pelatihan dan asesmen pada {start_date} s.d. {end_date} di {city}.',
        'vendor' => 'telah menyelesaikan program sertifikasi {program} pada {start_date} s.d. {end_date} di {city}.',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['validity_months' => 'integer', 'show_competencies' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'template_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Template efektif untuk paket & jenis: paket → default jenis → aktif pertama → dibuat otomatis. */
    public static function resolveFor(?TrainingPackage $package, string $type): self
    {
        if ($package?->certificate_template_id) {
            $t = static::query()->find($package->certificate_template_id);
            if ($t) {
                return $t;
            }
        }
        $t = static::query()->active()->where('type', $type)->orderByDesc('is_default')->orderBy('id')->first();

        return $t ?? static::createDefault($type);
    }

    /** Template bawaan per jenis (dibuat sekali, idempoten). */
    public static function createDefault(string $type): self
    {
        $label = self::TYPES[$type] ?? 'Sertifikat';

        return static::query()->firstOrCreate(['type' => $type, 'is_default' => true], [
            'name' => $label.' (bawaan)',
            'title' => strtoupper($label),
            'subtitle' => 'Diberikan kepada',
            'body' => self::DEFAULT_BODY[$type] ?? self::DEFAULT_BODY['participation'],
            'issuer_name' => 'PPTI - VMES Training Center',
            'issuer_title' => $type === 'competency' ? 'Lembaga Pelatihan Kerja' : null,
            'issuer_city' => 'Batam',
            'signatory_name' => 'Direktur PPTI - VMES',
            'signatory_title' => 'Direktur',
            'accent_color' => '#004B23',
            'orientation' => 'landscape',
            'number_format' => 'PPTI-{TYPE}-{YYYY}{MM}-{SEQ}',
            'validity_months' => $type === 'competency' ? 36 : null,
            'footer_text' => 'Keaslian sertifikat dapat diperiksa dengan memindai kode QR atau membuka halaman verifikasi.',
            'show_competencies' => true,
            'is_active' => true,
        ]);
    }
}
