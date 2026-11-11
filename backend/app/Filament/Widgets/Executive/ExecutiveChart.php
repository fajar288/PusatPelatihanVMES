<?php

namespace App\Filament\Widgets\Executive;

use Filament\Widgets\ChartWidget;

/** Dasar grafik Dashboard Eksekutif (Chart.js bawaan Filament). */
abstract class ExecutiveChart extends ChartWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected ?string $maxHeight = '280px';

    /** Palet tema PPTI (--p hijau, --l hijau muda, --o oranye) + pelengkap. */
    public const PALETTE = ['#004B23', '#6BB218', '#FA5000', '#0EA5E9', '#A855F7', '#EAB308', '#64748B', '#EF4444'];

    protected static function shorten(string $s, int $max = 34): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }
}
