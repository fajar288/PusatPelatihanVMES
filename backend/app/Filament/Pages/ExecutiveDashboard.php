<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Executive\AtRiskTable;
use App\Filament\Widgets\Executive\CertificateStatusChart;
use App\Filament\Widgets\Executive\ClassStatusChart;
use App\Filament\Widgets\Executive\FinanceStats;
use App\Filament\Widgets\Executive\LearningStats;
use App\Filament\Widgets\Executive\ParticipantStats;
use App\Filament\Widgets\Executive\PopularProgramsChart;
use App\Filament\Widgets\Executive\RevenueByProgramChart;
use App\Filament\Widgets\Executive\RevenueChart;
use App\Filament\Widgets\Executive\TrainerPerformanceTable;
use App\Filament\Widgets\Executive\TrendChart;
use App\Services\AnalyticsService;
use App\Services\ReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * /admin/dashboard-eksekutif — Executive dashboard manajemen (PRD §5.13, peran Management "Executive Dashboard").
 * Indikator peserta/alumni/kelas/kapasitas, keuangan, pembelajaran & peserta berisiko, trainer, sertifikat,
 * dengan filter periode, kategori, paket, kelas, trainer, dan status; dapat diekspor (AC-12).
 */
class ExecutiveDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/dashboard-eksekutif';

    protected static ?string $slug = 'dashboard-eksekutif';

    protected static ?string $title = 'Dashboard Eksekutif';

    protected static ?string $navigationLabel = 'Dashboard Eksekutif';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static ?int $navigationSort = -1;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('analytics.view') || $u?->isSuperAdmin());
    }

    public function getSubheading(): string|Htmlable|null
    {
        $d = app(AnalyticsService::class)->dashboard($this->filters);

        return 'Periode '.$d['period_label'].'. Diperbarui '.now()->format('d/m/Y H:i');
    }

    public function getWidgets(): array
    {
        return [
            ParticipantStats::class,
            FinanceStats::class,
            LearningStats::class,
            TrendChart::class,
            RevenueChart::class,
            PopularProgramsChart::class,
            RevenueByProgramChart::class,
            ClassStatusChart::class,
            CertificateStatusChart::class,
            AtRiskTable::class,
            TrainerPerformanceTable::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['md' => 2];
    }

    public function filtersForm(Schema $schema): Schema
    {
        $options = fn (Get $get) => app(AnalyticsService::class)->options(['category_id' => $get('category_id'), 'package_id' => $get('package_id')]);

        return $schema->components([
            Section::make('Filter')
                ->columnSpanFull()
                ->description('Periode berlaku untuk pendaftaran, invoice, pendapatan & sertifikat terbit; kelas dihitung bila jadwalnya beririsan dengan periode.')
                ->collapsible()
                ->columns(['md' => 3, 'xl' => 7])
                ->schema([
                    DatePicker::make('from')->label('Dari')->native(false)->displayFormat('d/m/Y')
                        ->default(fn () => today()->subMonths(11)->startOfMonth()->toDateString()),
                    DatePicker::make('to')->label('Sampai')->native(false)->displayFormat('d/m/Y')
                        ->default(fn () => today()->toDateString()),
                    Select::make('category_id')->label('Kategori')->placeholder('Semua kategori')->searchable()
                        ->options(fn (Get $get) => $options($get)['categories'])
                        ->afterStateUpdated(function (Set $set): void {
                            $set('package_id', null);
                            $set('schedule_id', null);
                        }),
                    Select::make('package_id')->label('Paket')->placeholder('Semua paket')->searchable()
                        ->options(fn (Get $get) => $options($get)['packages'])
                        ->afterStateUpdated(fn (Set $set) => $set('schedule_id', null)),
                    Select::make('schedule_id')->label('Kelas')->placeholder('Semua kelas')->searchable()
                        ->options(fn (Get $get) => $options($get)['schedules']),
                    Select::make('trainer_id')->label('Trainer')->placeholder('Semua trainer')->searchable()
                        ->options(fn (Get $get) => $options($get)['trainers']),
                    Select::make('status')->label('Status kelas')->placeholder('Semua status')
                        ->options(AnalyticsService::STATUS_LABELS),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $exports = [];
        foreach (ReportService::EXECUTIVE_TYPES as $type => $label) {
            foreach (['xlsx' => 'XLSX', 'csv' => 'CSV'] as $format => $fl) {
                $exports[] = Action::make("export_{$type}_{$format}")->label("$label ($fl)")
                    ->url(fn () => route('admin.export', ['type' => $type, 'format' => $format] + $this->exportQuery()), shouldOpenInNewTab: true);
            }
        }

        return [
            ActionGroup::make($exports)->label('Ekspor')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray'),
            Action::make('reset')->label('Reset filter')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function (): void {
                    $this->filters = null;
                    $this->getFiltersForm()->fill();
                    $this->updatedFilters();
                }),
        ];
    }

    /** @return array<string, mixed> */
    private function exportQuery(): array
    {
        return array_filter(app(AnalyticsService::class)->normalize($this->filters), fn ($v) => $v !== null && $v !== '');
    }
}
