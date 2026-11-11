<?php

namespace App\Filament\Widgets\Executive;

class PopularProgramsChart extends ExecutiveChart
{
    protected static ?int $sort = 12;

    protected ?string $heading = 'Program populer';

    protected ?string $description = 'Peserta per program pada kelas dalam periode (8 teratas)';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->analytics()['participants']['popular_programs'];

        return [
            'labels' => array_map(fn ($r) => self::shorten($r['title']), $rows),
            'datasets' => [
                ['label' => 'Peserta', 'data' => array_column($rows, 'participants'), 'backgroundColor' => self::PALETTE[0], 'borderRadius' => 4],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
