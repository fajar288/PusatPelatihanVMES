<?php

namespace App\Filament\Resources\TrainingSchedules\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Sesi/pertemuan kelas — dasar kehadiran (PRD §5.4, §5.9). */
class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'Sesi';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('session_no')->label('Sesi ke')->numeric()->minValue(1)->required()
                ->default(fn () => ($this->getOwnerRecord()->sessions()->max('session_no') ?? 0) + 1),
            DatePicker::make('date')->label('Tanggal')->required()->native(false),
            TimePicker::make('start_time')->label('Jam mulai')->seconds(false),
            TimePicker::make('end_time')->label('Jam selesai')->seconds(false),
            TextInput::make('topic')->label('Topik')->maxLength(255)->columnSpanFull(),
            Select::make('trainer_id')->label('Trainer sesi')->relationship('trainer', 'name')->searchable()->preload(),
            Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('topic')
            ->defaultSort('session_no')
            ->columns([
                TextColumn::make('session_no')->label('#')->sortable(),
                TextColumn::make('date')->label('Tanggal')->date('d M Y'),
                TextColumn::make('start_time')->label('Mulai')->time('H:i'),
                TextColumn::make('end_time')->label('Selesai')->time('H:i'),
                TextColumn::make('topic')->label('Topik')->searchable()->wrap(),
                TextColumn::make('trainer.name')->label('Trainer'),
                TextColumn::make('attendances_count')->label('Hadir')->counts('attendances'),
            ])
            ->headerActions([CreateAction::make()->label('Tambah sesi')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
