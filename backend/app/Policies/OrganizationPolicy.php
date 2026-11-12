<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\User;
use App\Services\CorporateAccess;
use Illuminate\Auth\Access\Response;

/**
 * Akses pengguna perusahaan ke workspace organisasi (Roadmap 5 §20.21). Ditolak sebagai 404 agar keberadaan
 * organisasi lain tidak bocor. Aturan detail (scope sponsor, unit, exit_access) ada di CorporateAccess.
 */
class OrganizationPolicy
{
    public function __construct(private CorporateAccess $access) {}

    /** Membuka workspace/daftar peserta organisasi: harus pengguna perusahaan aktif dari organisasi aktif. */
    public function viewCorporate(User $user, Organization $organization): Response
    {
        return $this->access->membership($user, $organization) ? Response::allow() : Response::denyAsNotFound();
    }

    /** Detail progres satu enrollment: bersponsor organisasi ini, dalam batas unit, dan sesuai kebijakan exit_access. */
    public function viewEnrollmentProgress(User $user, Organization $organization, Enrollment $enrollment): Response
    {
        $cu = $this->access->membership($user, $organization);

        return $cu && $this->access->canViewProgress($cu, $enrollment) ? Response::allow() : Response::denyAsNotFound();
    }
}
