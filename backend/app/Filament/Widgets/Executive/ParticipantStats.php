<?php

namespace App\Filament\Widgets\Executive;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Peserta, alumni, kelas & kapasitas (PRD §5.13 butir 1). */
class ParticipantStats extends StatsOverviewWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Peserta & kelas';

    protected function getColumns(): int
    {
        return 5;
    }

    protected function getStats(): array
    {
        $d = $this->analytics();
        $p = $d['participants'];
        $c = $d['classes'];
        $ph = collect($c['by_phase'])->map(fn ($x) => $x['count']);

        return [
            Stat::make('Pendaftar', number_format($p['registrations'], 0, ',', '.'))
                ->description(self::lines($p['registrations_participants'].' peserta', 'Konversi '.self::pct($p['conversion_rate'])))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->chart(array_values($p['registrations_by_month']))->color('primary'),
            Stat::make('Peserta aktif', number_format($p['active'], 0, ',', '.'))
                ->description($p['enrolled'].' peserta terdaftar di kelas')
                ->icon(Heroicon::OutlinedUserGroup),
            Stat::make('Alumni', number_format($p['alumni_total'], 0, ',', '.'))
                ->description('+'.$p['alumni_new'].' alumni baru pada periode')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)->color('success')
                ->icon(Heroicon::OutlinedAcademicCap),
            Stat::make('Kelas berjalan', (string) ($ph['running'] ?? 0))
                ->description(self::lines(($ph['completed'] ?? 0).' selesai', ($ph['upcoming'] ?? 0).' akan datang', ($ph['cancelled'] ?? 0).' batal'))
                ->icon(Heroicon::OutlinedCalendarDays),
            Stat::make('Kapasitas terisi', self::pct($c['capacity']['utilization']))
                ->description(self::lines($c['capacity']['filled'].' / '.$c['capacity']['quota'].' kursi', $c['capacity']['full_classes'].' kelas penuh'))
                ->icon(Heroicon::OutlinedChartPie)
                ->color(($c['capacity']['utilization'] ?? 0) >= 70 ? 'success' : 'warning'),
        ];
    }
}
