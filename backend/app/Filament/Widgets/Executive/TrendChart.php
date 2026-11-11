<?php

namespace App\Filament\Widgets\Executive;

use App\Services\AnalyticsService;

class TrendChart extends ExecutiveChart
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Tren pendaftaran & sertifikat';

    protected ?string $description = 'Jumlah pendaftaran dan sertifikat terbit per bulan';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $d = $this->analytics();
        $reg = $d['participants']['registrations_by_month'];

        return [
            'labels' => array_map([AnalyticsService::class, 'monthLabel'], array_keys($reg)),
            'datasets' => [
                ['label' => 'Pendaftaran', 'data' => array_values($reg), 'borderColor' => self::PALETTE[0], 'backgroundColor' => 'rgba(0,75,35,.12)', 'fill' => true, 'tension' => 0.3],
                ['label' => 'Sertifikat terbit', 'data' => array_values($d['certificates']['issued_by_month']), 'borderColor' => self::PALETTE[2], 'backgroundColor' => 'rgba(250,80,0,.08)', 'tension' => 0.3],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
