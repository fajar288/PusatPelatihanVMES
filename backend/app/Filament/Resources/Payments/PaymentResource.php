<?php

namespace App\Filament\Resources\Payments;

use App\Enums\RegistrationStatus;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Services\RegistrationService;
use App\Support\Media;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Verifikasi pembayaran oleh finance (PRD §5.11): verifikasi → pendaftaran terkonfirmasi; tolak → kembali menunggu pembayaran. */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Pembayaran';

    protected static ?string $modelLabel = 'Pembayaran';

    protected static ?string $pluralModelLabel = 'Pembayaran';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('invoice_number')->label('Invoice')->searchable(),
                TextColumn::make('registration.registration_number')->label('Registrasi')->searchable(),
                TextColumn::make('registration.name')->label('Pendaftar'),
                TextColumn::make('amount')->label('Jumlah')->money('IDR', locale: 'id'),
                TextColumn::make('due_date')->label('Jatuh tempo')->date('d M Y'),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'verified' => 'success', 'submitted' => 'warning', 'rejected' => 'danger', default => 'gray',
                }),
                TextColumn::make('proof_path')->label('Bukti')->formatStateUsing(fn ($state) => $state ? 'Lihat bukti' : '-')
                    ->url(fn (Payment $p) => Media::url($p->proof_path), shouldOpenInNewTab: true),
                TextColumn::make('proof_uploaded_at')->label('Diunggah')->dateTime('d M Y H:i'),
            ])
            ->filters([SelectFilter::make('status')->options(array_combine(Payment::STATUSES, Payment::STATUSES))])
            ->recordActions([
                Action::make('verifikasi')->label('Verifikasi')->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Payment $p) => $p->status === 'submitted')
                    ->action(function (Payment $p, RegistrationService $service): void {
                        $p->update(['status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now()]);
                        AuditLog::record('payment.verified', $p, ['status' => 'submitted'], ['status' => 'verified']);
                        $reg = $p->registration;
                        if ($reg && $reg->status->canTransitionTo(RegistrationStatus::Confirmed)) {
                            $service->transition($reg, RegistrationStatus::Confirmed, auth()->user(), 'Pembayaran terverifikasi');
                        }
                        Notification::make()->title('Pembayaran diverifikasi')->success()->send();
                    }),
                Action::make('tolak')->label('Tolak')->icon('heroicon-o-x-circle')->color('danger')
                    ->schema([Textarea::make('note')->label('Alasan')->required()])
                    ->visible(fn (Payment $p) => $p->status === 'submitted')
                    ->action(function (array $data, Payment $p, RegistrationService $service): void {
                        $p->update(['status' => 'rejected', 'note' => $data['note'], 'verified_by' => auth()->id(), 'verified_at' => now()]);
                        AuditLog::record('payment.rejected', $p, ['status' => 'submitted'], ['status' => 'rejected', 'note' => $data['note']]);
                        $reg = $p->registration;
                        if ($reg && $reg->status->canTransitionTo(RegistrationStatus::WaitingPayment)) {
                            $service->transition($reg, RegistrationStatus::WaitingPayment, auth()->user(), 'Bukti pembayaran ditolak: '.$data['note']);
                        }
                        Notification::make()->title('Bukti pembayaran ditolak')->warning()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
