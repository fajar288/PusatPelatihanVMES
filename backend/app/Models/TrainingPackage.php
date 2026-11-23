<?php

namespace App\Models;

use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrainingPackage extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /** Label mode untuk tampilan (identik dengan website lama). */
    public const MODE_LABELS = [
        'online' => 'online',
        'offline' => 'offline',
        'hybrid' => 'hybrid',
        'online_offline' => 'online / offline',
    ];

    /** availability → pill status kartu (.st.ok / .st.no). */
    public const AVAILABILITY = [
        'available' => ['ok', 'Tersedia'],
        'coming_soon' => ['no', 'Segera dibuka'],
        'closed' => ['no', 'Ditutup'],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'overview_intro' => 'array',
            'competencies' => 'array',
            'learning_outcomes' => 'array',
            'audience' => 'array',
            'requirements' => 'array',
            'price' => 'decimal:2',
            'is_popular' => 'boolean',
            'completion_rule' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public const PROGRAM_TYPES = ['regular' => 'Reguler', 'in_house' => 'In-house', 'private' => 'Private', 'online' => 'Online', 'blended' => 'Blended', 'custom' => 'Custom perusahaan', 'bundling' => 'Bundling'];

    public const CERTIFICATE_TYPES = ['participation' => 'Sertifikat keikutsertaan', 'competency' => 'Sertifikat kompetensi', 'vendor' => 'Sertifikasi vendor'];

    public const COMPLETION_DEFAULT = ['min_attendance_pct' => 80, 'min_score' => null, 'require_assignment' => false, 'require_posttest' => false];

    /** Aturan kelulusan efektif (default + override paket). @return array<string, mixed> */
    public function completionRule(): array
    {
        return array_merge(self::COMPLETION_DEFAULT, array_filter($this->completion_rule ?? [], fn ($v) => $v !== null && $v !== ''));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TrainingCategory::class, 'category_id');
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(TrainingCurriculum::class, 'package_id')->orderBy('sort_order');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TrainingSchedule::class, 'package_id');
    }

    public function rubrics(): HasMany
    {
        return $this->hasMany(AssessmentRubric::class, 'package_id');
    }

    /** Kompetensi yang dibangun program ini (Roadmap 5F) + level yang dicapai lulusannya. */
    public function competencyLinks(): BelongsToMany
    {
        return $this->belongsToMany(Competency::class, 'package_competencies', 'package_id', 'competency_id')
            ->withPivot(['id', 'level'])->withTimestamps();
    }

    /** Program yang harus sudah diluluskan sebelum mengikuti program ini (rekomendasi "program lanjutan"). */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'package_prerequisites', 'package_id', 'prerequisite_id')->withTimestamps();
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PUBLISHED)
            ->where(fn (Builder $w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(function (Builder $w) use ($term) {
            Sql::whereContains($w, 'title', $term);
            Sql::orWhereContains($w, 'vendor', $term);
            Sql::orWhereContains($w, 'excerpt', $term);
            $w->orWhereHas('category', fn (Builder $c) => Sql::whereContains($c, 'name', $term));
        });
    }

    public function modeLabel(): string
    {
        return self::MODE_LABELS[$this->mode] ?? $this->mode;
    }

    /** @return array{0:string,1:string} [kode pill, label] */
    public function availabilityPill(): array
    {
        return self::AVAILABILITY[$this->availability] ?? ['no', ucfirst((string) $this->availability)];
    }
}
