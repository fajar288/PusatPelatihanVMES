<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jawaban survei satu peserta untuk satu kelas (pending saat diundang, submitted setelah diisi). */
class SurveyResponse extends Model
{
    public const STATUSES = ['pending' => 'Belum diisi', 'submitted' => 'Sudah diisi'];

    protected $fillable = ['template_id', 'schedule_id', 'enrollment_id', 'member_id', 'status', 'answers', 'invited_at', 'submitted_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'invited_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    /** @return BelongsTo<SurveyTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SurveyTemplate::class, 'template_id');
    }

    /** @return BelongsTo<TrainingSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<FeedbackLabel, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(FeedbackLabel::class, 'response_id');
    }

    /** Komentar terisi: key pertanyaan → teks. @return array<string, string> */
    public function comments(): array
    {
        $out = [];
        foreach ($this->template?->questionList() ?? [] as $q) {
            $v = trim((string) ($this->answers[$q['key']] ?? ''));
            if ($q['type'] === 'comment' && mb_strlen($v) >= 3) {
                $out[$q['key']] = $v;
            }
        }

        return $out;
    }

    /** Rata-rata skor skala 1-5 dari jawaban ini. */
    public function averageScore(): ?float
    {
        $scores = [];
        foreach ($this->template?->questionList() ?? [] as $q) {
            if ($q['type'] === 'scale' && is_numeric($this->answers[$q['key']] ?? null)) {
                $scores[] = (float) $this->answers[$q['key']];
            }
        }

        return $scores ? round(array_sum($scores) / count($scores), 2) : null;
    }
}
