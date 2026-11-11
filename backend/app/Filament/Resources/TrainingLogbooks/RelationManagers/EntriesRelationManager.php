<?php

namespace App\Filament\Resources\TrainingLogbooks\RelationManagers;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\LogbookEntry;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Catatan logbook per peserta (status praktik + observasi individual). Perubahan dicatat ke audit log logbook induk. */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Catatan peserta';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('enrollment_id')->label('Peserta')->required()->searchable()
                ->options(fn () => Enrollment::query()->where('schedule_id', $this->getOwnerRecord()->schedule_id)->orderBy('participant_name')->pluck('participant_name', 'id'))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('logbook_id', $this->getOwnerRecord()->id)),
            Select::make('practice_status')->label('Status praktik')->options(LogbookEntry::PRACTICE)->native(false),
            Textarea::make('observation')->label('Observasi / catatan')->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        $audit = fn (string $action) => fn ($record) => AuditLog::record($this->getOwnerRecord()->is_locked ? 'logbook.updated_after_validation' : 'logbook.updated', $this->getOwnerRecord(), null, ['entry' => $action, 'enrollment_id' => $record->enrollment_id, 'practice_status' => $record->practice_status, 'observation' => $record->observation]);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('enrollment'))
            ->columns([
                TextColumn::make('enrollment.participant_name')->label('Peserta')->searchable(),
                TextColumn::make('practice_status')->label('Praktik')->badge()->formatStateUsing(fn ($state) => LogbookEntry::PRACTICE[$state] ?? '-')
                    ->color(fn ($state) => match ($state) {
                        'completed' => 'success', 'partial' => 'warning', 'not_done' => 'danger', default => 'gray'
                    }),
                TextColumn::make('observation')->label('Observasi')->limit(80)->wrap(),
            ])
            ->headerActions([CreateAction::make()->label('Tambah catatan')->after($audit('created'))])
            ->recordActions([EditAction::make()->after($audit('updated')), DeleteAction::make()->after($audit('deleted'))]);
    }
}
