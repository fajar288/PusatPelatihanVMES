<?php

namespace App\Filament\Resources\Attendances\Tables;

use App\Models\Attendance;
use App\Models\ClassSession;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class AttendancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('session.schedule.code')->label('Kelas')->searchable(),
                TextColumn::make('session.session_no')->label('Sesi')->alignCenter(),
                TextColumn::make('session.date')->label('Tanggal')->date('d M Y'),
                TextColumn::make('enrollment.participant_name')->label('Peserta')->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Attendance::LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'present' => 'success', 'late' => 'warning', 'excused' => 'gray', default => 'danger'
                    }),
                TextColumn::make('checked_at')->label('Dicatat')->dateTime('d M Y H:i'),
                TextColumn::make('note')->label('Catatan')->limit(40),
            ])
            ->filters([
                SelectFilter::make('session_id')->label('Sesi')->options(fn () => ClassSession::query()->with('schedule')->orderByDesc('date')->limit(200)->get()->mapWithKeys(fn ($s) => [$s->id => ($s->schedule?->code ?? '').' · '.$s->label()])),
                SelectFilter::make('status')->options(Attendance::LABELS),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([
                BulkActionGroup::make(array_map(
                    fn (string $st) => BulkAction::make("set_$st")->label('Tandai: '.Attendance::LABELS[$st])
                        ->action(fn (Collection $records) => $records->each->update(['status' => $st, 'checked_at' => now(), 'checked_by' => auth()->id()]))
                        ->deselectRecordsAfterCompletion(),
                    Attendance::STATUSES,
                )),
            ]);
    }
}
