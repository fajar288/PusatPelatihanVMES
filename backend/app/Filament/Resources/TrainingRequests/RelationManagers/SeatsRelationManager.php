<?php

namespace App\Filament\Resources\TrainingRequests\RelationManagers;

use App\Filament\Support\EnterpriseFields;
use App\Models\RequestSeat;
use App\Services\ParticipantAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Kursi 01..N: status, peserta, metode penugasan, enrollment. Penugasan dilakukan perusahaan; VMES bisa membatalkan. */
class SeatsRelationManager extends RelationManager
{
    protected static string $relationship = 'requestSeats';

    protected static ?string $title = 'Kursi & peserta';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('seat_no')
            ->columns([
                TextColumn::make('seat_no')->label('Kursi')->formatStateUsing(fn ($state) => str_pad((string) $state, 2, '0', STR_PAD_LEFT)),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => RequestSeat::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'enrolled' => 'success', 'assigned' => 'primary', 'invited' => 'warning', default => 'gray'
                    }),
                TextColumn::make('participant_name')->label('Peserta')->placeholder('-')->description(fn (RequestSeat $record) => $record->participant_email),
                TextColumn::make('member.member_number')->label('Member')->placeholder('-'),
                TextColumn::make('unit.name')->label('Unit')->placeholder('-'),
                TextColumn::make('assignment_method')->label('Metode')->placeholder('-'),
                TextColumn::make('enrollment_id')->label('Enrollment')->placeholder('-')->formatStateUsing(fn ($state) => '#'.$state),
            ])
            ->filters([SelectFilter::make('status')->options(RequestSeat::STATUSES)])
            ->recordActions([
                Action::make('release')->label('Batalkan peserta')->icon('heroicon-o-user-minus')->color('danger')
                    ->visible(fn (RequestSeat $record) => ! $record->isFree())
                    ->modalDescription('Hanya enrollment peserta di kursi ini yang dibatalkan. Kursi kembali kosong.')
                    ->schema([TextInput::make('reason')->label('Alasan')->required()->maxLength(500)])
                    ->action(fn (array $data, RequestSeat $record, Action $action) => EnterpriseFields::attempt($action,
                        fn () => app(ParticipantAssignmentService::class)->releaseSeat($record, auth()->user(), $data['reason']), 'Peserta dibatalkan')),
            ]);
    }
}
