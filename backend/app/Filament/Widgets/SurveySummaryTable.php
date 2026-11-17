<?php

namespace App\Filament\Widgets;

use App\Services\SurveyService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/** Rata-rata skor survei per program dan per trainer (tanpa AI). */
class SurveySummaryTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Rata-rata per program dan trainer')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $svc = app(SurveyService::class);
                $rows = collect($svc->summaryBy('package'))->map(fn ($r) => $r + ['dimension' => 'Program', 'id' => 'p'.$r['key']])
                    ->merge(collect($svc->summaryBy('trainer'))->map(fn ($r) => $r + ['dimension' => 'Trainer', 'id' => 't'.$r['key']]));

                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage)->keyBy('id')->all(), $rows->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('dimension')->label('Jenis')->badge()->color('gray'),
                TextColumn::make('name')->label('Nama')->weight('bold'),
                TextColumn::make('responses')->label('Survei terisi')->alignEnd(),
                TextColumn::make('average')->label('Rata-rata')->alignEnd()->placeholder('-')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').' / 5'),
            ])
            ->paginated([10, 25])
            ->emptyStateHeading('Belum ada survei terisi');
    }
}
