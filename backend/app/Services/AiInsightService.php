<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\AiUsageRecord;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Ai\AiText;
use App\Support\Ai\AiUseCases;
use App\Support\Ai\DataReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya penulis tabel ai_insights. Keluaran AI hanya disimpan sebagai insight, tidak pernah mengubah kelulusan,
 * nilai, kompetensi, sertifikat, enrollment, invoice, atau pembayaran. Siklus: draft → approved | rejected | expired.
 *
 * Use case dengan review "required" selalu mulai sebagai draft dan baru tampil setelah disetujui admin (izin ai.review).
 * Use case "staff"/"logged" langsung berstatus approved tanpa peninjau (tetap dicatat dan bisa ditolak belakangan).
 */
class AiInsightService
{
    /**
     * @param  array<string, mixed>  $output  keluaran (teks di dalamnya dinormalkan AiText sebelum disimpan)
     */
    public function record(
        string $useCase,
        array $output,
        DataReference $reference,
        ?Model $subject = null,
        ?float $score = null,
        ?float $confidence = null,
        ?int $organizationId = null,
        ?string $modelVersion = null,
        ?AiUsageRecord $usage = null,
        ?\DateTimeInterface $expiresAt = null,
    ): AiInsight {
        $uc = AiUseCases::get($useCase);

        return DB::transaction(function () use ($uc, $output, $reference, $subject, $score, $confidence, $organizationId, $modelVersion, $usage, $expiresAt) {
            $insight = AiInsight::query()->create([
                'use_case' => $uc['key'],
                'organization_id' => $organizationId,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'model_version' => $modelVersion ?? ($uc['method'] === AiUseCases::METHOD_LLM ? $uc['model'] : 'statistical'),
                'prompt_version' => $uc['prompt_version'],
                'data_reference' => $reference->toArray(),
                'output' => self::normalize($output),
                'score' => $score,
                'confidence' => $confidence,
                'status' => $uc['review'] === 'required' ? AiInsight::STATUS_DRAFT : AiInsight::STATUS_APPROVED,
                'expires_at' => $expiresAt,
            ]);
            $usage?->update(['ai_insight_id' => $insight->id]);

            return $insight;
        });
    }

    public function approve(AiInsight $insight, User $reviewer): AiInsight
    {
        $this->assertReviewer($reviewer);

        return $this->decide($insight, $reviewer, AiInsight::STATUS_APPROVED, null, [AiInsight::STATUS_DRAFT]);
    }

    /** Tolak insight (juga insight yang sudah tampil, mis. rekomendasi yang ternyata keliru). */
    public function reject(AiInsight $insight, User $reviewer, string $reason): AiInsight
    {
        $this->assertReviewer($reviewer);
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['rejection_reason' => 'Tuliskan alasan penolakan (minimal 10 karakter).']);
        }

        return $this->decide($insight, $reviewer, AiInsight::STATUS_REJECTED, trim($reason), [AiInsight::STATUS_DRAFT, AiInsight::STATUS_APPROVED]);
    }

    /** Insight lama (draft/approved) untuk subjek yang sama digantikan hasil baru: ditandai kedaluwarsa. */
    public function supersede(string $useCase, Model $subject, ?int $exceptId = null): int
    {
        return AiInsight::query()->where('use_case', $useCase)
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->whereIn('status', [AiInsight::STATUS_DRAFT, AiInsight::STATUS_APPROVED])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->update(['status' => AiInsight::STATUS_EXPIRED, 'updated_at' => now()]);
    }

    /** Draft yang tidak ditinjau dalam N hari dan insight yang lewat expires_at menjadi kedaluwarsa. */
    public function expireDue(): int
    {
        $ttl = max(1, (int) config('ai.insight_ttl_days'));

        return AiInsight::query()
            ->whereIn('status', [AiInsight::STATUS_DRAFT, AiInsight::STATUS_APPROVED])
            ->where(fn ($q) => $q->where('expires_at', '<=', now())
                ->orWhere(fn ($q) => $q->where('status', AiInsight::STATUS_DRAFT)->whereNull('expires_at')->where('created_at', '<=', now()->subDays($ttl))))
            ->update(['status' => AiInsight::STATUS_EXPIRED, 'updated_at' => now()]);
    }

    /** Umpan balik "membantu / tidak membantu" (satu per pengguna per insight, bisa diubah). Hanya insight yang tampil. */
    public function feedback(AiInsight $insight, User $user, bool $helpful, ?string $note = null): AiInsightFeedback
    {
        if (! AiInsight::query()->visible()->whereKey($insight->id)->exists()) {
            throw ValidationException::withMessages(['insight' => 'Insight ini tidak sedang ditampilkan.']);
        }

        return AiInsightFeedback::query()->updateOrCreate(
            ['ai_insight_id' => $insight->id, 'user_id' => $user->id],
            ['helpful' => $helpful, 'note' => $note !== null ? mb_substr(trim($note), 0, 500) : null],
        );
    }

    /**
     * Normalkan semua teks keluaran AI (tanpa em dash / en dash) secara rekursif.
     *
     * @param  array<mixed>  $output
     * @return array<mixed>
     */
    public static function normalize(array $output): array
    {
        return array_map(fn ($v) => match (true) {
            is_string($v) => AiText::normalize($v),
            is_array($v) => self::normalize($v),
            default => $v,
        }, $output);
    }

    /** @param  list<string>  $from */
    private function decide(AiInsight $insight, User $reviewer, string $to, ?string $reason, array $from): AiInsight
    {
        return DB::transaction(function () use ($insight, $reviewer, $to, $reason, $from) {
            $locked = AiInsight::query()->lockForUpdate()->findOrFail($insight->id);
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'Insight ini sudah '.mb_strtolower(AiInsight::STATUSES[$locked->status] ?? $locked->status).'.']);
            }
            $before = ['status' => $locked->status];
            $locked->update([
                'status' => $to,
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);
            AuditLog::record($to === AiInsight::STATUS_APPROVED ? 'ai.insight_approved' : 'ai.insight_rejected', $locked, $before, ['status' => $to, 'reason' => $reason], $reviewer);

            return $locked;
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->hasPermission('ai.review')) {
            throw ValidationException::withMessages(['reviewer' => 'Anda tidak berwenang meninjau insight AI.']);
        }
    }
}
