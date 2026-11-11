<?php

namespace App\Filament\Widgets\Executive;

use App\Services\AnalyticsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Perilaku bersama widget Dashboard Eksekutif: membaca filter halaman, data dari AnalyticsService (di-memo per request),
 * hanya untuk pemegang izin analytics.view, dan tidak ikut tampil di dashboard bawaan (isDiscovered = false pada kelas).
 */
trait ExecutiveWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('analytics.view') || $u?->isSuperAdmin());
    }

    /** @return array<string, mixed> */
    protected function analytics(): array
    {
        return app(AnalyticsService::class)->dashboard($this->pageFilters);
    }

    protected static function rupiah(float|int|null $v, bool $short = false): string
    {
        $v = (float) $v;
        if ($short && abs($v) >= 1_000_000_000) {
            return 'Rp '.number_format($v / 1_000_000_000, 1, ',', '.').' M';
        }
        if ($short && abs($v) >= 1_000_000) {
            return 'Rp '.number_format($v / 1_000_000, 1, ',', '.').' jt';
        }

        return 'Rp '.number_format($v, 0, ',', '.');
    }

    protected static function pct(float|int|null $v): string
    {
        return $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',').'%';
    }
}
