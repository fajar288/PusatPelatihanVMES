<?php

namespace App\Filament\Widgets\Executive;

/** Funnel penjualan corporate (PRD Roadmap 5 §8 Sales Funnel) dalam satuan Corporate Training Request. */
class CorporateFunnelChart extends ExecutiveChart
{
    protected static ?int $sort = 16;

    protected ?string $heading = 'Funnel penjualan corporate';

    public function getDescription(): ?string
    {
        $f = $this->sales()['funnel'];
        $paid = collect($f['stages'])->firstWhere('key', 'paid');

        return 'Request dibuat dalam periode: '.$f['organizations'].' organisasi, '.$f['seats_paid'].' kursi lunas, '.$f['cancelled'].' dibatalkan. Konversi lead → lunas '.self::pct($paid['from_lead'] ?? null).'.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $stages = $this->sales()['funnel']['stages'];

        return [
            'labels' => array_map(fn ($s) => $s['label'].($s['from_previous'] !== null ? ' ('.self::pct($s['from_previous']).')' : ''), $stages),
            'datasets' => [[
                'label' => 'Request',
                'data' => array_column($stages, 'count'),
                'backgroundColor' => array_map(fn ($s) => $s['key'] === 'repeat' ? self::PALETTE[2] : self::PALETTE[0], $stages),
                'borderRadius' => 4,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
