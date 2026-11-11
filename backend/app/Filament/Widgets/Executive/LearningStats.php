<?php

namespace App\Filament\Widgets\Executive;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Pembelajaran, trainer & sertifikat (PRD §5.13 butir 3–5). */
class LearningStats extends StatsOverviewWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Pembelajaran, trainer & sertifikat';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $d = $this->analytics();
        $l = $d['learning'];
        $t = $d['trainers'];
        $c = $d['certificates'];
        $risk = count($l['at_risk']);

        return [
            Stat::make('Completion rate', self::pct($l['completion_rate']))
                ->description($l['completed'].' lulus · '.$l['failed'].' tidak lulus')
                ->icon(Heroicon::OutlinedCheckBadge)->color(($l['completion_rate'] ?? 100) >= 80 ? 'success' : 'warning'),
            Stat::make('Rata-rata kehadiran', self::pct($l['avg_attendance']))
                ->description('Progres LMS '.self::pct($l['avg_lms_progress']).' · kuis '.($l['avg_quiz'] ?? '—'))
                ->icon(Heroicon::OutlinedPresentationChartLine),
            Stat::make('Hasil evaluasi', self::pct($l['competent_rate']))
                ->description('kompeten dari '.$l['assessments_validated'].' penilaian · nilai akhir '.($l['avg_final_score'] ?? '—'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck),
            Stat::make('Peserta berisiko', (string) $risk)
                ->description($risk > 0 ? 'perlu tindak lanjut — lihat tabel di bawah' : 'tidak ada peserta tertinggal')
                ->icon(Heroicon::OutlinedExclamationTriangle)->color($risk > 0 ? 'danger' : 'success'),
            Stat::make('Trainer bertugas', $t['assigned'].' / '.$t['active'])
                ->description($t['assignments'].' penugasan · '.$t['sessions_held'].' sesi mengajar')
                ->icon(Heroicon::OutlinedUsers),
            Stat::make('Logbook', (string) $t['logbooks'])
                ->description($t['logbooks_validated'].' tervalidasi')
                ->icon(Heroicon::OutlinedBookOpen),
            Stat::make('Sertifikat terbit', (string) $c['issued'])
                ->description($c['awaiting'].' menunggu proses (draf/validasi)')
                ->icon(Heroicon::OutlinedTrophy)->color('success')
                ->chart(array_values($c['issued_by_month'])),
            Stat::make('Penilaian kompetensi', (string) $l['assessments'])
                ->description('rata-rata skor '.self::pct($l['avg_competency']))
                ->icon(Heroicon::OutlinedStar),
        ];
    }
}
