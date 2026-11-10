<?php

namespace App\Filament\Resources\TrainingSchedules\RelationManagers;

use App\Models\ScheduleChange;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Riwayat perubahan jadwal (hanya baca) — PRD §5.4. */
class ChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'changes';

    protected static ?string $title = 'Riwayat perubahan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i'),
                TextColumn::make('changer.name')->label('Oleh')->default('system'),
                TextColumn::make('after')->label('Perubahan')->state(fn (ScheduleChange $c) => collect($c->after ?? [])->map(fn ($v, $k) => "$k: ".($c->before[$k] ?? '-')." → $v")->implode('; '))->wrap(),
                TextColumn::make('reason')->label('Alasan')->wrap(),
                TextColumn::make('notified_count')->label('Diberitahu')->alignCenter(),
            ]);
    }
}
