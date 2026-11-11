<?php

namespace App\Filament\Widgets\Executive;

class ClassStatusChart extends ExecutiveChart
{
    protected static ?int $sort = 14;

    protected ?string $heading = 'Status kelas';

    protected ?string $description = 'Kelas yang berlangsung dalam periode, menurut fase';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = array_values($this->analytics()['classes']['by_phase']);

        return [
            'labels' => array_column($rows, 'label'),
            'datasets' => [
                ['data' => array_column($rows, 'count'), 'backgroundColor' => [self::PALETTE[3], self::PALETTE[1], self::PALETTE[0], self::PALETTE[7], self::PALETTE[6]]],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'right']], 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}
