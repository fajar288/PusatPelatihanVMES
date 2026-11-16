<?php

namespace App\Filament\Widgets\Executive;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Corporate vs self-funded (Roadmap 5F): porsi enrollment, peserta, order lunas & AOV per segmen. */
class SegmentStats extends StatsOverviewWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Corporate vs mandiri (self-funded)';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $d = $this->sales();
        $s = $d['segments'];
        $kpi = $d['kpi'];

        return [
            Stat::make('Enrollment mandiri', (string) $s['self']['enrollments'])
                ->description(self::lines(self::pct($s['self']['share']).' dari total', $s['self']['participants'].' peserta · '.$s['self']['completed'].' lulus'))
                ->icon(Heroicon::OutlinedUser),
            Stat::make('Enrollment corporate', (string) $s['corporate']['enrollments'])
                ->description(self::lines(self::pct($s['corporate']['share']).' dari total', $s['corporate']['organizations'].' organisasi sponsor · '.$s['corporate']['completed'].' lulus'))
                ->icon(Heroicon::OutlinedBuildingOffice2)->color('info'),
            Stat::make('Order mandiri', self::rupiah($s['self']['order_value'], true))
                ->description(self::lines($s['self']['orders'].' order lunas', 'AOV '.($kpi['self']['average_order_value']['value'] === null ? '—' : self::rupiah($kpi['self']['average_order_value']['value'], true))))
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make('Order corporate', self::rupiah($s['corporate']['order_value'], true))
                ->description(self::lines($s['corporate']['orders'].' order lunas', 'AOV '.($kpi['corporate']['average_order_value']['value'] === null ? '—' : self::rupiah($kpi['corporate']['average_order_value']['value'], true))))
                ->icon(Heroicon::OutlinedBriefcase)->color('info'),
        ];
    }
}
