<?php

namespace App\Services;

use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batas akses data perusahaan, selalu di server (PRD Roadmap 5 §20.11, §20.19 R3, §20.21).
 * - Organisasi hanya terlihat oleh pengguna perusahaan AKTIF dari organisasi AKTIF itu.
 * - Enrollment hanya yang disponsori organisasi tsb (+ batas unit pengguna). Self-funded & sponsor lain tidak pernah masuk.
 * - Peserta yang employment-nya di organisasi tsb sudah INACTIVE mengikuti kebijakan exit_access organisasi.
 */
class CorporateAccess
{
    /** Keanggotaan aktif user di organisasi (null = tidak berhak). */
    public function membership(?User $user, Organization $org): ?CorporateUser
    {
        if (! $user || ! $org->isActive()) {
            return null;
        }

        return CorporateUser::query()->active()->where('organization_id', $org->id)->where('user_id', $user->id)->first();
    }

    /** Organisasi tempat user menjadi pengguna perusahaan aktif. */
    public function memberships(User $user)
    {
        return CorporateUser::query()->active()->where('user_id', $user->id)
            ->whereHas('organization', fn (Builder $q) => $q->active())->with(['organization', 'unit'])->get();
    }

    /** Semua enrollment bersponsor dalam scope pengguna (dipakai juga untuk agregat — exit tetap dihitung). */
    public function sponsoredEnrollments(CorporateUser $cu): Builder
    {
        return Enrollment::query()->visibleToCorporateUser($cu);
    }

    /** Enrollment yang boleh tampil per nama di daftar (exit_access=none menyembunyikan peserta yang sudah keluar). */
    public function listableEnrollments(CorporateUser $cu): Builder
    {
        $q = $this->sponsoredEnrollments($cu);
        if ($cu->organization->exitAccess() === 'none') {
            $exited = $this->exitedMemberIds($cu->organization_id);
            $q->where(fn (Builder $w) => $w->whereNull('member_id')->orWhereNotIn('member_id', $exited));
        }

        return $q;
    }

    public function canView(CorporateUser $cu, Enrollment $e): bool
    {
        return $cu->isActive() && $this->sponsoredEnrollments($cu)->whereKey($e->id)->exists()
            && ! ($cu->organization->exitAccess() === 'none' && $this->hasExited($e));
    }

    /** Detail progres (kehadiran, LMS, penilaian, logbook ringkas). Ditutup untuk peserta yang sudah keluar kecuali exit_access=full. */
    public function canViewProgress(CorporateUser $cu, Enrollment $e): bool
    {
        return $cu->allows('view_progress') && $this->canView($cu, $e)
            && ($cu->organization->exitAccess() === 'full' || ! $this->hasExited($e));
    }

    /** Status sertifikat dari enrollment bersponsor (§11 "corporate sees only sponsored record"). */
    public function canViewCertificateStatus(CorporateUser $cu, Enrollment $e): bool
    {
        return $this->canView($cu, $e);
    }

    /** Peserta pernah bekerja di organisasi sponsor dan tidak lagi punya employment aktif di sana. */
    public function hasExited(Enrollment $e): bool
    {
        if (! $e->member_id || ! $e->sponsor_organization_id) {
            return false;
        }
        $q = EmploymentRelationship::query()->where('member_id', $e->member_id)->where('organization_id', $e->sponsor_organization_id);

        return (clone $q)->exists() && ! (clone $q)->active()->exists();
    }

    /** @return list<int> */
    public function exitedMemberIds(int $organizationId): array
    {
        $active = EmploymentRelationship::query()->active()->where('organization_id', $organizationId)->pluck('member_id')->all();

        return EmploymentRelationship::query()->where('organization_id', $organizationId)->whereNotIn('member_id', $active)
            ->distinct()->pluck('member_id')->all();
    }
}
