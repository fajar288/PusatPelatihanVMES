<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\CorporateUser;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Models\User;

/**
 * Siapa boleh melihat (dan memberi umpan balik) sebuah insight AI yang sedang tampil.
 *
 * - Staf VMES dengan izin ai.review / ai.manage / analytics.view.
 * - Early warning: trainer yang ditugaskan di kelas peserta, dan pengguna perusahaan sponsor yang boleh melihat progres
 *   peserta itu (aturan sama dengan detail progres di Corporate Portal: sponsor, batas unit, kebijakan karyawan keluar).
 * - Peserta sendiri TIDAK melihat skor early warning.
 * - Asisten Belajar: jawaban hanya untuk peserta yang bertanya (dan staf AI di atas).
 * - Insight perusahaan: pengguna perusahaan tingkat organisasi (tanpa batas unit) yang boleh melihat progres atau
 *   mengelola kompetensi di organisasi itu.
 */
class AiInsightAccess
{
    public function __construct(private readonly CorporateAccess $corporate) {}

    public function canView(User $user, AiInsight $insight): bool
    {
        if (! AiInsight::query()->visible()->whereKey($insight->id)->exists()) {
            return false;
        }
        if ($user->hasPermission('ai.review') || $user->hasPermission('ai.manage') || $user->hasPermission('analytics.view')) {
            return true;
        }
        if ($insight->use_case === AiRecommendationService::USE_CASE) {
            return $this->isRecipient($user, $insight);
        }
        // Insight perusahaan (sudah disetujui admin): pengguna perusahaan tingkat organisasi di organisasi tersebut.
        if ($insight->use_case === AiCorporateInsightService::USE_CASE) {
            $org = $insight->organization_id ? Organization::query()->find($insight->organization_id) : null;
            $cu = $org ? $this->corporate->membership($user, $org) : null;

            return $cu !== null && app(AiCorporateInsightService::class)->canView($cu);
        }
        // Asisten Belajar: hanya peserta pemilik enrollment yang bertanya (bukan trainer, bukan perusahaan sponsor).
        if ($insight->use_case === AiLearningAssistantService::USE_CASE) {
            return $insight->subject_type === (new Enrollment)->getMorphClass()
                && Enrollment::query()->ownedBy($user)->whereKey($insight->subject_id)->exists();
        }
        // Ringkasan umpan balik kelas (sudah disetujui admin): trainer yang ditugaskan di kelas itu.
        if ($insight->use_case === AiFeedbackService::USE_CASE && $insight->subject_type === (new TrainingSchedule)->getMorphClass()
            && ($insight->output['kind'] ?? null) === 'class_summary') {
            $trainer = Trainer::query()->where('user_id', $user->id)->where('is_active', true)->first();

            return $trainer !== null && $trainer->schedules()->whereKey($insight->subject_id)->exists();
        }
        if ($insight->use_case !== AiEarlyWarningService::USE_CASE || $insight->subject_type !== (new Enrollment)->getMorphClass()) {
            return false;
        }
        $e = Enrollment::query()->find($insight->subject_id);
        if (! $e) {
            return false;
        }
        $trainer = Trainer::query()->where('user_id', $user->id)->where('is_active', true)->first();
        if ($trainer && $trainer->schedules()->whereKey($e->schedule_id)->exists()) {
            return true;
        }
        $org = $insight->organization_id ? Organization::query()->find($insight->organization_id) : null;
        $cu = $org ? $this->corporate->membership($user, $org) : null;

        return $cu !== null && $e->sponsor_organization_id === $org->id && $this->corporate->canViewProgress($cu, $e);
    }

    /**
     * Penerima langsung insight (rekomendasi): member pemilik akun, atau pengguna perusahaan aktif yang menjadi subjeknya.
     * Hanya penerima yang klik dan umpan baliknya dicatat sebagai metrik.
     */
    public function isRecipient(User $user, AiInsight $insight): bool
    {
        return match ($insight->subject_type) {
            (new Member)->getMorphClass() => Member::query()->whereKey($insight->subject_id)->where('user_id', $user->id)->exists(),
            (new CorporateUser)->getMorphClass() => ($cu = CorporateUser::query()->with('organization')->find($insight->subject_id)) !== null
                && $cu->user_id === $user->id && $this->corporate->membership($user, $cu->organization)?->id === $cu->id,
            default => false,
        };
    }
}
