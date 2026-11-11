<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Widgets\LaporanStats;
use App\Services\FinanceService;
use App\Services\ReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** /admin/laporan — ringkasan operasional & pendapatan + ekspor CSV/XLSX (task Phase 2 "Laporan operasional + ekspor"). */
class Laporan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Analytics;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan';

    protected static ?string $slug = 'laporan';

    protected string $view = 'filament.pages.laporan';

    public ?string $from = null;

    public ?string $to = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('reports.view') || $u?->isAdmin());
    }

    public function resetRange(): void
    {
        $this->from = null;
        $this->to = null;
    }

    protected function getHeaderWidgets(): array
    {
        return [LaporanStats::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $reports = app(ReportService::class);
        $q = array_filter(['from' => $this->from, 'to' => $this->to]);

        return [
            'monthly' => array_slice(app(FinanceService::class)->revenueSummary()['by_month'], -6, null, true),
            'types' => ReportService::TYPES,
            'exportUrl' => fn (string $type, string $format) => route('admin.export', ['type' => $type, 'format' => $format] + $q),
            'preview' => collect(ReportService::TYPES)->mapWithKeys(fn ($label, $type) => [$type => $reports->toAssoc($reports->dataset($type, $this->from, $this->to))->take(8)]),
        ];
    }
}
