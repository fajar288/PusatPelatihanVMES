<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\Registrations\RegistrationResource;
use App\Models\Registration;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Riwayat pendaftaran peserta (baca saja), tautan ke resource Pendaftaran. */
class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    protected static ?string $title = 'Riwayat pendaftaran';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('registration_number')
            ->modifyQueryUsing(fn ($query) => $query->with(['schedule.package']))
            ->columns([
                TextColumn::make('registration_number')->label('Nomor')->searchable(),
                TextColumn::make('schedule.code')->label('Kelas')->placeholder('-'),
                TextColumn::make('program')->label('Program')->state(fn (Registration $record) => $record->schedule?->title ?? $record->schedule?->package?->title ?? '-')->wrap(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RegistrationStatus ? $state->label() : $state),
                TextColumn::make('total')->label('Total')->money('IDR', locale: 'id')->placeholder('-'),
                TextColumn::make('created_at')->label('Tanggal')->date('d M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('open')->label('Buka')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn () => RegistrationResource::canViewAny())
                    ->url(fn (Registration $record) => RegistrationResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
