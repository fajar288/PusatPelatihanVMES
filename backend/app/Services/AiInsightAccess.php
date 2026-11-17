<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Trainer;
use App\Models\User;

/**
 * Siapa boleh melihat (dan memberi umpan balik) sebuah insight AI yang sedang tampil.
 *
 * - Staf VMES dengan izin ai.review / ai.manage / analytics.view.
 * - Early warning: trainer yang ditugaskan di kelas peserta, dan pengguna perusahaan sponsor yang boleh melihat progres
 *   peserta itu (aturan sama dengan detail progres di Corporate Portal: sponsor, batas unit, kebijakan karyawan keluar).
 * - Peserta sendiri TIDAK melihat skor early warning.
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
}
