<?php

namespace App\Filament\Widgets\Executive;

use App\Services\KpiService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/** KPI resmi PRD Roadmap 5 §8.1 per segmen (Semua / Mandiri / Corporate): definisi persis docs/KPI.md. */
class KpiTable extends TableWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $col = fn (string $seg, string $label) => TextColumn::make($seg)->label($label)->alignEnd()
            ->description(fn (array $record) => $record[$seg.'_detail'])->weight('bold');

        return $table
            ->heading('Indikator Kinerja Utama (KPI)')
            ->description('Repeat order hanya dihitung untuk akun perusahaan. At-Risk Rate memakai aturan peserta berisiko di bawah.')
            ->records(fn (): LengthAwarePaginator => new LengthAwarePaginator(
                collect($this->sales()['kpi_table'])->keyBy('key')->all(), count(KpiService::KEYS), count(KpiService::KEYS), 1,
            ))
            ->columns([
                TextColumn::make('label')->label('KPI')->weight('bold')->description(fn (array $record) => $record['definition'])->wrap(),
                $col('all', KpiService::SEGMENTS['all']),
                $col('self', 'Mandiri'),
                $col('corporate', 'Corporate'),
            ])
            ->paginated(false);
    }
}
