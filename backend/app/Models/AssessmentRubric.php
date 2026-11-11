<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rubrik penilaian kompetensi per program (PRD §5.9) — dapat disesuaikan per paket. */
class AssessmentRubric extends Model
{
    /** Kriteria bawaan PRD §5.9 bila program belum punya rubrik sendiri. */
    public const DEFAULT_CRITERIA = [
        ['code' => 'theory', 'name' => 'Pemahaman teori', 'description' => 'Memahami konsep dan dasar teori materi pelatihan.'],
        ['code' => 'practice', 'name' => 'Keterampilan praktik', 'description' => 'Melaksanakan praktik sesuai prosedur dan standar.'],
        ['code' => 'troubleshooting', 'name' => 'Troubleshooting', 'description' => 'Mengidentifikasi dan menyelesaikan masalah teknis.'],
        ['code' => 'tools', 'name' => 'Penggunaan alat', 'description' => 'Menggunakan alat/perangkat dengan benar dan aman.'],
        ['code' => 'assignment', 'name' => 'Penyelesaian tugas', 'description' => 'Menyelesaikan tugas tepat waktu sesuai instruksi.'],
        ['code' => 'independence', 'name' => 'Kemandirian', 'description' => 'Bekerja mandiri, inisiatif, dan bertanggung jawab.'],
    ];

    public const DEFAULT_LEVELS = [
        ['score' => 1, 'label' => 'Belum kompeten', 'description' => 'Belum mampu meski dibimbing.'],
        ['score' => 2, 'label' => 'Cukup', 'description' => 'Mampu dengan bimbingan intensif.'],
        ['score' => 3, 'label' => 'Kompeten', 'description' => 'Mampu mandiri sesuai standar.'],
        ['score' => 4, 'label' => 'Sangat kompeten', 'description' => 'Melampaui standar, mampu membimbing.'],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class, 'package_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class, 'rubric_id')->orderBy('sort_order');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(CompetencyAssessment::class, 'rubric_id');
    }

    /** Rubrik aktif program; dibuat dari kriteria bawaan bila belum ada. */
    public static function activeFor(TrainingPackage $package): self
    {
        $rubric = static::query()->where('package_id', $package->id)->where('is_active', true)->orderByDesc('id')->first();
        if ($rubric) {
            return $rubric;
        }
        $rubric = static::query()->create(['package_id' => $package->id, 'name' => 'Rubrik '.$package->title, 'description' => 'Rubrik bawaan (PRD §5.9); sesuaikan kriteria dengan kompetensi program.']);
        foreach (self::DEFAULT_CRITERIA as $i => $c) {
            $rubric->criteria()->create($c + ['weight' => 1, 'max_score' => 4, 'levels' => self::DEFAULT_LEVELS, 'sort_order' => $i + 1]);
        }

        return $rubric;
    }
}
