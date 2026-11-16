<?php

namespace App\Filament\Widgets\Executive;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/** Cohort pelanggan & repeat order (PRD Roadmap 5 §8 layer Customer: repeat rate, time-to-repeat, AOV). */
class CohortTable extends TableWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 22;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Cohort & repeat order')
            ->description('Pelanggan dikelompokkan menurut bulan order lunas pertama dalam periode; repeat = membeli lagi sampai akhir periode. Pelanggan corporate = organisasi, mandiri = member. Filter program tidak berlaku di tabel ini.')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $rows = collect($this->sales()['cohorts']);

                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage)->keyBy('key')->all(), $rows->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('segment_label')->label('Segmen')->badge()->color(fn (array $record) => $record['segment'] === 'corporate' ? 'info' : 'gray'),
                TextColumn::make('cohort_label')->label('Cohort'),
                TextColumn::make('customers')->label('Pelanggan')->numeric(),
                TextColumn::make('repeat')->label('Repeat')->numeric(),
                TextColumn::make('repeat_rate')->label('Repeat rate')->formatStateUsing(fn ($state) => self::pct($state)),
                TextColumn::make('avg_days_to_repeat')->label('Hari ke order ke-2')->placeholder('-'),
                TextColumn::make('orders')->label('Order')->numeric(),
                TextColumn::make('aov')->label('AOV')->formatStateUsing(fn ($state) => $state === null ? '-' : self::rupiah($state)),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Belum ada order lunas')
            ->emptyStateDescription('Cohort muncul setelah ada invoice lunas dalam periode.');
    }
}
