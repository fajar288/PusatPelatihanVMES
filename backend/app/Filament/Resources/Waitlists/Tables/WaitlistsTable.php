<?php

namespace App\Filament\Resources\Waitlists\Tables;

use App\Models\AuditLog;
use App\Models\Registration;
use App\Models\Waitlist;
use App\Services\RegistrationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Daftar tunggu: tawarkan kursi → konversi menjadi pendaftaran (pending) saat kursi tersedia. */
class WaitlistsTable
{
    private const STATUS = ['waiting' => 'Menunggu', 'offered' => 'Ditawari', 'converted' => 'Dikonversi', 'cancelled' => 'Dibatalkan'];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('schedule.code')->label('Kelas')->searchable()->description(fn (Waitlist $w) => $w->schedule?->package?->title),
                TextColumn::make('name')->label('Nama')->searchable()->description(fn (Waitlist $w) => $w->email),
                TextColumn::make('phone')->label('Telepon'),
                TextColumn::make('participants_count')->label('Peserta')->alignCenter(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => self::STATUS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'converted' => 'success', 'offered' => 'warning', 'cancelled' => 'danger', default => 'gray'
                    }),
                TextColumn::make('schedule.registered_count')->label('Terisi')->state(fn (Waitlist $w) => $w->schedule ? "{$w->schedule->registered_count}/{$w->schedule->quota}" : '-'),
                TextColumn::make('created_at')->label('Masuk')->dateTime('d M Y H:i'),
            ])
            ->filters([SelectFilter::make('status')->options(self::STATUS)->default('waiting')])
            ->recordActions([
                Action::make('tawarkan')->label('Tawarkan')->icon('heroicon-o-bell-alert')->color('warning')
                    ->visible(fn (Waitlist $w) => $w->status === 'waiting')
                    ->action(function (Waitlist $w): void {
                        $w->update(['status' => 'offered', 'offered_at' => now()]);
                        AuditLog::record('waitlist.offered', $w);
                        Notification::make()->title('Ditandai ditawari — hubungi pendaftar untuk konfirmasi')->success()->send();
                    }),
                Action::make('konversi')->label('Jadikan pendaftaran')->icon('heroicon-o-arrow-right-circle')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Waitlist $w) => in_array($w->status, ['waiting', 'offered'], true))
                    ->action(function (Waitlist $w, RegistrationService $service): void {
                        $reg = $service->create([
                            'type' => $w->participants_count > 1 ? Registration::TYPE_CORPORATE : Registration::TYPE_INDIVIDUAL,
                            'name' => $w->name, 'email' => $w->email, 'phone' => $w->phone ?? '-', 'participants_count' => $w->participants_count,
                            'notes' => 'Dari daftar tunggu', 'consent' => true,
                        ], $w->schedule, $w->member?->user, [], 'waitlist');
                        $w->update(['status' => 'converted', 'registration_id' => $reg->id]);
                        AuditLog::record('waitlist.converted', $w, null, ['registration' => $reg->registration_number]);
                        Notification::make()->title("Pendaftaran {$reg->registration_number} dibuat")->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
