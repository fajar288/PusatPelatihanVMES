<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Satu keluaran AI (insight). Ditulis HANYA oleh AiInsightService. Keluaran AI tidak pernah mengubah data resmi;
 * insight hanya dibaca, ditinjau (setujui/tolak), atau kedaluwarsa.
 * `data_reference` berisi ID dan hash data sumber (DataReference), tidak pernah salinan data pribadi.
 *
 * @property string $use_case
 * @property string $status
 * @property string|null $model_version
 * @property string|null $prompt_version
 * @property array<string, mixed>|null $data_reference
 * @property array<string, mixed>|null $output
 */
class AiInsight extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Menunggu tinjauan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
    ];

    protected $fillable = [
        'use_case', 'organization_id', 'subject_type', 'subject_id', 'model_version', 'prompt_version', 'data_reference',
        'output', 'score', 'confidence', 'status', 'reviewer_id', 'reviewed_at', 'rejection_reason', 'expires_at',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'data_reference' => 'array',
            'output' => 'array',
            'score' => 'float',
            'confidence' => 'float',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<AiInsightFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(AiInsightFeedback::class);
    }

    /** Insight yang boleh tampil ke pengguna: disetujui dan belum kedaluwarsa. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_REJECTED, self::STATUS_EXPIRED], true);
    }
}
