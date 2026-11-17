<?php

namespace App\Support\Ai\Evaluators;

use App\Services\AiCorporateInsightService;

/**
 * Insight perusahaan: acceptance rate (disetujui vs ditolak admin VMES) dan konversi menjadi permintaan training dalam
 * 30 hari setelah insight disetujui, dibanding organisasi bertarget yang hanya melihat laporan gap tanpa narasi.
 */
class CorporateInsightEvaluator implements Evaluator
{
    public function __construct(private readonly AiCorporateInsightService $service) {}

    public function evaluate(): array
    {
        $m = $this->service->evaluate();
        $notes = [];
        if ($m['approved'] + $m['rejected'] === 0) {
            $notes[] = 'Belum ada insight yang diputuskan admin, acceptance rate belum bisa dihitung.';
        }
        if ($m['mature'] === 0) {
            $notes[] = 'Belum ada insight disetujui yang berumur '.AiCorporateInsightService::CONVERSION_DAYS.' hari, konversi belum bisa dihitung.';
        }
        if ($m['baseline_organizations'] === 0) {
            $notes[] = 'Tidak ada organisasi pembanding (semua organisasi bertarget sudah menerima insight).';
        }
        $notes[] = 'Perbandingan dengan organisasi tanpa insight bukan uji terkontrol; pakai sebagai indikasi, bukan bukti sebab-akibat.';

        return [
            'metrics' => $m,
            'baseline' => ['description' => 'Organisasi bertarget yang hanya melihat laporan gap tanpa narasi',
                'organizations' => $m['baseline_organizations'], 'conversion_rate' => $m['baseline_conversion_rate']],
            'notes' => $notes,
        ];
    }
}
