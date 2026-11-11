<?php

namespace App\Filament\Widgets\Executive;

class CertificateStatusChart extends ExecutiveChart
{
    protected static ?int $sort = 15;

    protected ?string $heading = 'Status penerbitan sertifikat';

    protected ?string $description = 'Seluruh sertifikat untuk kelas sesuai filter';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = array_values($this->analytics()['certificates']['by_status']);

        return [
            'labels' => array_column($rows, 'label'),
            'datasets' => [
                ['data' => array_column($rows, 'count'), 'backgroundColor' => array_slice([self::PALETTE[6], self::PALETTE[5], self::PALETTE[1], self::PALETTE[7], self::PALETTE[4]], 0, count($rows))],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'right']], 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}
