<?php

namespace App\Filament\Resources\NotificationDeliveries\Pages;

use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Widgets\NotificationDeliveryStats;
use App\Models\AuditLog;
use App\Support\WhatsApp\FonnteWhatsAppSender;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppSender;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;
use Throwable;

class ListNotificationDeliveries extends ListRecords
{
    protected static string $resource = NotificationDeliveryResource::class;

    protected function getHeaderWidgets(): array
    {
        return [NotificationDeliveryStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Uji kirim langsung lewat WhatsAppSender (di luar Notification Engine); boleh sebelum WHATSAPP_ENABLED=true
            Action::make('testWhatsApp')->label('Kirim WA uji')->icon('heroicon-o-chat-bubble-left-ellipsis')->color('gray')
                ->visible(fn () => config('notifications.whatsapp.driver') === 'fonnte')
                ->modalDescription('Mengirim satu pesan teks ke nomor yang Anda isi lewat penyedia WhatsApp yang dipasang. Tercatat di jejak audit.')
                ->schema([
                    TextInput::make('phone')->label('Nomor WhatsApp tujuan')->placeholder('0812-3456-7890')->required()->maxLength(30)
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! PhoneNumber::normalize((string) $value)) {
                                $fail('Nomor WhatsApp tidak valid.');
                            }
                        }),
                    Textarea::make('message')->label('Pesan')->required()->rows(3)->maxLength(1000)
                        ->default('Uji notifikasi WhatsApp dari PPTI VMES. Abaikan pesan ini.'),
                ])
                ->action(function (array $data): void {
                    $sender = app(WhatsAppSender::class);
                    if ($sender instanceof FonnteWhatsAppSender && ! $sender->configured()) {
                        Notification::make()->title('FONNTE_TOKEN belum diisi.')->danger()->send();

                        return;
                    }
                    $to = (string) PhoneNumber::normalize($data['phone']);
                    try {
                        $id = $sender->send($to, (string) $data['message']);
                    } catch (Throwable $e) {
                        AuditLog::record('whatsapp.test_failed', null, null, ['provider' => $sender->provider(), 'to' => $to, 'error' => Str::limit($e->getMessage(), 300, '')]);
                        Notification::make()->title('WA uji gagal dikirim')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    AuditLog::record('whatsapp.test_sent', null, null, ['provider' => $sender->provider(), 'to' => $to, 'message_id' => $id]);
                    Notification::make()->title('WA uji dikirim ke '.$to)->body('ID pesan: '.$id)->success()->send();
                }),
        ];
    }
}
