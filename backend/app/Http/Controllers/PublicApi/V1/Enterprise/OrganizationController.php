<?php

namespace App\Http\Controllers\PublicApi\V1\Enterprise;

use App\Services\CorporateReporting;
use App\Support\Enterprise\EnterpriseApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API enterprise — profil organisasi (scope organization.read) & KPI ringkas (scope analytics.read). */
class OrganizationController extends EnterpriseController
{
    /** Definisi KPI yang dibuka lewat API (PRD Roadmap 5 §7 "Approved KPI access", §20.9 Overview). */
    public const KPI = [
        'sponsored_participants' => 'Jumlah member unik dengan enrollment bersponsor organisasi (semua status)',
        'active_enrollments' => 'Enrollment bersponsor berstatus enrolled/attending',
        'completed_enrollments' => 'Enrollment bersponsor berstatus completed',
        'completion_rate' => 'completed ÷ enrollment bersponsor yang tidak dibatalkan (dropped), dalam persen; null bila belum ada',
        'attendance_avg' => 'Rata-rata persentase kehadiran enrollment bersponsor yang sudah mulai; null bila peran tidak boleh melihat progres',
        'certificates_issued' => 'Enrollment bersponsor dengan sertifikat berstatus issued',
        'active_employees' => 'Employment relationship aktif dalam batas unit; null bila peran tidak boleh melihat karyawan',
        'seats_bought' => 'Total kursi request yang tidak draft/dibatalkan',
        'seats_filled' => 'Kursi yang sudah berisi peserta (assigned/enrolled)',
        'requests_by_status' => 'Jumlah Corporate Training Request per status',
    ];

    /** GET /corporate/organization */
    public function show(Request $request): JsonResponse
    {
        $cu = $this->cu($request);

        return response()->json(['data' => EnterpriseApiPresenter::organization($cu->organization, $cu)]);
    }

    /** GET /analytics — agregat menghitung semua enrollment bersponsor dalam batas unit (termasuk peserta yang sudah keluar). */
    public function analytics(Request $request, CorporateReporting $reporting): JsonResponse
    {
        $cu = $this->cu($request);
        $s = $reporting->stats($cu);

        return response()->json(['data' => [
            'organization' => $cu->organization->code,
            'generated_at' => now()->toIso8601String(),
            'scope' => ['unit' => EnterpriseApiPresenter::unit($cu->unit), 'funding' => 'corporate_sponsored'],
            'kpi' => [
                'sponsored_participants' => $s['participants'],
                'active_enrollments' => $s['activeEnrollments'],
                'completed_enrollments' => $s['completed'],
                'completion_rate' => $s['completionRate'],
                'attendance_avg' => $s['attendanceAvg'],
                'certificates_issued' => $s['certificates'],
                'active_employees' => $s['activeEmployees'],
                'seats_bought' => $s['seatsBought'],
                'seats_filled' => $s['seatsFilled'],
                'requests_by_status' => (object) $s['requests'],
            ],
            'definitions' => self::KPI,
        ]]);
    }
}
