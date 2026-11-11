<?php

namespace App\Filament\Widgets\Executive;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/** Jumlah trainer, penugasan, aktivitas mengajar & evaluasi (PRD §5.13 butir 4). */
class TrainerPerformanceTable extends TableWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 21;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $pct = fn ($state) => $state === null ? '—' : rtrim(rtrim(number_format((float) $state, 1, ',', '.'), '0'), ',').'%';

        return $table
            ->heading('Kinerja trainer')
            ->description('Penugasan & aktivitas mengajar pada kelas dalam periode; evaluasi = rata-rata skor kompetensi tervalidasi & completion rate peserta.')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $rows = collect($this->analytics()['trainers']['rows'])->map(fn ($r) => $r + ['key' => $r['trainer_id']]);

                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage)->keyBy('key')->all(), $rows->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('name')->label('Trainer')->description(fn (array $record) => $record['title']),
                TextColumn::make('classes')->label('Kelas')->numeric(),
                TextColumn::make('sessions_held')->label('Sesi mengajar')->formatStateUsing(fn ($state, array $record) => $state.' / '.$record['sessions_planned']),
                TextColumn::make('logbooks')->label('Logbook')->formatStateUsing(fn ($state, array $record) => $state.' ('.$record['logbooks_validated'].' valid)'),
                TextColumn::make('participants')->label('Peserta')->numeric(),
                TextColumn::make('assessments')->label('Penilaian')->numeric(),
                TextColumn::make('avg_competency')->label('Skor kompetensi')->formatStateUsing($pct)->placeholder('—'),
                TextColumn::make('completion_rate')->label('Completion')->formatStateUsing($pct)->placeholder('—'),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Belum ada penugasan trainer')
            ->emptyStateDescription('Tidak ada trainer yang ditugaskan pada kelas sesuai filter.');
    }
}
