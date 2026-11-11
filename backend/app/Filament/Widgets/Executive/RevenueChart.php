<?php

namespace App\Filament\Widgets\Executive;

use App\Services\AnalyticsService;

class RevenueChart extends ExecutiveChart
{
    protected static ?int $sort = 11;

    protected ?string $heading = 'Pendapatan per bulan';

    protected ?string $description = 'Pembayaran terverifikasi (Rp), berdasarkan tanggal verifikasi';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rev = $this->analytics()['finance']['revenue_by_month'];

        return [
            'labels' => array_map([AnalyticsService::class, 'monthLabel'], array_keys($rev)),
            'datasets' => [
                ['label' => 'Pendapatan (Rp)', 'data' => array_values($rev), 'backgroundColor' => self::PALETTE[1], 'borderRadius' => 4],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['beginAtZero' => true]]];
    }
}
