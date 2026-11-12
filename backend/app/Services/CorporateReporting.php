<?php

namespace App\Services;

use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\RequestSeat;
use App\Models\TrainingRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ringkasan & progres yang boleh dilihat perusahaan — dipakai bersama Corporate Portal (5B) dan API enterprise (5E)
 * agar batas privasi (§20.10, §20.11) dan scope (R3, batas unit, exit_access) identik di kedua jalur.
 */
class CorporateReporting
{
    public function __construct(private CorporateAccess $access, private MonitoringService $monitoring) {}

    /** Request organisasi dalam batas unit pengguna perusahaan. */
    public function requestsQuery(CorporateUser $cu): Builder
    {
        $scope = $cu->unitScope();

        return TrainingRequest::query()->where('organization_id', $cu->organization_id)->when($scope !== null, fn (Builder $q) => $q->whereIn('unit_id', $scope));
    }

    /** Karyawan organisasi dalam batas unit (default hanya yang aktif). */
    public function employeesQuery(CorporateUser $cu, bool $activeOnly = true): Builder
    {
        $scope = $cu->unitScope();

        return EmploymentRelationship::query()->where('organization_id', $cu->organization_id)
            ->when($activeOnly, fn (Builder $q) => $q->active())
            ->when($scope !== null, fn (Builder $q) => $q->whereIn('unit_id', $scope));
    }

    /**
     * Invoice organisasi (tanpa yang dibatalkan): training request (mengikuti batas unit pengguna) + pendaftaran kolektif
     * yang ditautkan ke organisasi (hanya untuk pengguna tanpa batas unit).
     */
    public function invoicesQuery(CorporateUser $cu): Builder
    {
        $scope = $cu->unitScope();

        return Invoice::query()->forOrganization($cu->organization_id)->where('status', '!=', 'cancelled')
            ->when($scope !== null, fn (Builder $q) => $q->whereHas('trainingRequest', fn (Builder $r) => $r->whereIn('unit_id', $scope)));
    }

    /**
     * Enrollment yang detail progresnya boleh dibuka (§20.10 + kebijakan exit_access): daftar enrollment, lalu peserta
     * yang sudah keluar dari organisasi disaring kecuali exit_access = full. Setara CorporateAccess::canViewProgress().
     */
    public function progressQuery(CorporateUser $cu): Builder
    {
        $q = $this->access->listableEnrollments($cu);
        if ($cu->organization->exitAccess() !== 'full') {
            $exited = $this->access->exitedMemberIds($cu->organization_id);
            $q->where(fn (Builder $w) => $w->whereNull('member_id')->orWhereNotIn('member_id', $exited));
        }

        return $q;
    }

    /** KPI ringkas dashboard perusahaan (§20.9 Overview). Agregat menghitung semua enrollment bersponsor (termasuk peserta yang sudah keluar). */
    public function stats(CorporateUser $cu): array
    {
        $all = $this->access->sponsoredEnrollments($cu);
        $active = (clone $all)->whereIn('status', ['enrolled', 'attending'])->count();
        $done = (clone $all)->where('status', 'completed')->count();
        $counted = (clone $all)->where('status', '!=', 'dropped')->count();
        $attendance = $cu->allows('view_progress')
            ? (clone $all)->whereIn('status', ['attending', 'completed', 'failed'])->with('schedule.sessions', 'attendances')->get()->map->attendancePercent()->filter(fn ($v) => $v !== null)
            : collect();
        $requests = $this->requestsQuery($cu)->get(['id', 'status', 'seats']);
        $live = $requests->whereNotIn('status', ['cancelled', 'draft']);
        $filled = $live->isEmpty() ? 0 : RequestSeat::query()->whereIn('training_request_id', $live->pluck('id'))->whereIn('status', ['assigned', 'enrolled'])->count();

        return [
            'participants' => (clone $all)->whereNotNull('member_id')->distinct()->count('member_id'),
            'activeEnrollments' => $active,
            'completed' => $done,
            'completionRate' => $counted > 0 ? (int) round($done / $counted * 100) : null,
            'attendanceAvg' => $attendance->isNotEmpty() ? (int) round($attendance->avg()) : null,
            'certificates' => (clone $all)->whereHas('certificates', fn (Builder $q) => $q->where('status', 'issued'))->count(),
            'activeEmployees' => $cu->allows('view_progress') || $cu->allows('manage_participants') ? $this->employeesQuery($cu)->count() : null,
            'requests' => $requests->countBy('status')->all(),
            'seatsBought' => (int) $live->sum('seats'),
            'seatsFilled' => $filled,
        ];
    }

    /**
     * Ringkasan progres satu enrollment untuk perusahaan (§20.10): hanya logbook & penilaian tervalidasi, tanpa catatan
     * privat trainer, observasi, nama penilai, maupun email peserta (§20.11). Pemanggil wajib sudah memeriksa
     * CorporateAccess::canViewProgress().
     */
    public function progress(Enrollment $enrollment): array
    {
        $o = $this->monitoring->overview($enrollment, forParticipant: true);
        unset($o['enrollment']['email']);
        $o['notes'] = collect($o['notes'])->map(fn ($n) => array_diff_key($n, ['observation' => true, 'trainer' => true]))->values()->all();
        if (isset($o['competency']) && is_array($o['competency'])) {
            $o['competency'] = array_diff_key($o['competency'], array_flip(['summary', 'recommendation', 'assessedBy', 'validatedBy']));
            $o['competency']['criteria'] = collect($o['competency']['criteria'] ?? [])->map(fn ($c) => array_diff_key((array) $c, ['note' => true, 'description' => true]))->values()->all();
        }

        return $o;
    }

    /** Status hubungan kerja peserta di organisasi sponsor: active | inactive | unknown. */
    public function employmentStatus(Enrollment $e, ?EmploymentRelationship $active): string
    {
        return $active ? 'active' : ($this->access->hasExited($e) ? 'inactive' : 'unknown');
    }
}
