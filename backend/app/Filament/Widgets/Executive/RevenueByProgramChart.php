<?php

namespace App\Filament\Widgets\Executive;

class RevenueByProgramChart extends ExecutiveChart
{
    protected static ?int $sort = 13;

    protected ?string $heading = 'Pendapatan per program';

    protected ?string $description = 'Pembayaran terverifikasi pada periode (8 teratas, Rp)';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->analytics()['finance']['revenue_by_program'];

        return [
            'labels' => array_map(fn ($r) => self::shorten($r['title']), $rows),
            'datasets' => [
                ['label' => 'Pendapatan (Rp)', 'data' => array_column($rows, 'amount'), 'backgroundColor' => self::PALETTE[1], 'borderRadius' => 4],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['beginAtZero' => true]]];
    }
}
