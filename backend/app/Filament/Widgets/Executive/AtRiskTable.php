<?php

namespace App\Filament\Widgets\Executive;

use App\Filament\Resources\TrainingSchedules\TrainingScheduleResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/** Peserta berisiko tertinggal (PRD §5.13 butir 3) — kehadiran, progres LMS, nilai kuis, aktivitas. */
class AtRiskTable extends TableWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Peserta berisiko tertinggal')
            ->description('Peserta aktif di kelas yang sudah dimulai: kehadiran di bawah aturan kelulusan, progres materi tertinggal dari jadwal, rata-rata kuis < 60, atau tidak aktif di LMS.')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $rows = collect($this->analytics()['learning']['at_risk'])->map(fn ($r) => $r + ['key' => $r['enrollment_id'], 'reasons_text' => implode("\n", $r['reasons'])]);

                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage)->keyBy('key')->all(), $rows->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('name')->label('Peserta')->description(fn (array $record) => $record['email']),
                TextColumn::make('class')->label('Kelas')->description(fn (array $record) => $record['program'])
                    ->url(fn (array $record) => TrainingScheduleResource::getUrl('edit', ['record' => $record['class']])),
                TextColumn::make('attendance')->label('Kehadiran')->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%')->placeholder('—'),
                TextColumn::make('lms')->label('Progres LMS')->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%')->placeholder('—'),
                TextColumn::make('quiz')->label('Kuis')->placeholder('—'),
                TextColumn::make('reasons')->label('Alasan')->badge()->color('danger')->wrap(),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Tidak ada peserta berisiko')
            ->emptyStateDescription('Semua peserta aktif berada di jalur yang baik untuk filter ini.');
    }
}
