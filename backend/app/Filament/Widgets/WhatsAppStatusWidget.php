<?php

namespace App\Filament\Widgets;

use App\Support\WhatsApp\FonnteWhatsAppSender;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

/**
 * Status gateway WhatsApp (Fonnte) di dashboard admin: device, nomor, paket, sisa kuota, kedaluwarsa.
 * Hanya tampil bila WHATSAPP_DRIVER=fonnte; data dari POST /device di-cache 5 menit. API gagal → pesan ramah, bukan error.
 */
class WhatsAppStatusWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 5;

    protected static ?bool $isLazyLoaded = true;

    protected ?string $heading = 'Status WhatsApp (Fonnte)';

    public static function canView(): bool
    {
        $u = auth()->user();

        return config('notifications.whatsapp.driver') === 'fonnte'
            && (bool) ($u?->hasPermission('notifications.manage') || $u?->isSuperAdmin());
    }

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 2, 'xl' => 5];
    }

    protected function getStats(): array
    {
        $sender = app(FonnteWhatsAppSender::class);
        $active = (bool) config('notifications.whatsapp.enabled');
        if (! $sender->configured()) {
            return [Stat::make('Status WhatsApp', 'Belum dikonfigurasi')
                ->description('Isi FONNTE_TOKEN di .env (lihat docs/whatsapp-fonnte.md).')
                ->color('gray')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)];
        }
        try {
            $p = $sender->deviceProfile();
        } catch (Throwable $e) {
            report($e);

            return [Stat::make('Status WhatsApp', 'Tidak dapat dihubungi')
                ->description('Profil device Fonnte gagal diambil. Coba lagi beberapa menit lagi atau cek token di dashboard Fonnte.')
                ->color('danger')->icon(Heroicon::OutlinedExclamationTriangle)];
        }
        $connected = $p['status'] === 'connect';

        return [
            Stat::make('Device', $connected ? 'Tersambung' : ($p['status'] === 'disconnect' ? 'Terputus' : ($p['status'] ?? '-')))
                ->description($connected ? ($active ? 'Kanal WA aktif' : 'Kanal WA belum diaktifkan (WHATSAPP_ENABLED=false)') : 'Scan ulang QR di dashboard Fonnte')
                ->color($connected ? 'success' : 'danger')
                ->icon($connected ? Heroicon::OutlinedSignal : Heroicon::OutlinedSignalSlash),
            Stat::make('Nomor', $p['device'] ?? '-')->description($p['name'] ?? '')->icon(Heroicon::OutlinedDevicePhoneMobile),
            Stat::make('Paket', $p['package'] ?? '-')->icon(Heroicon::OutlinedRectangleStack),
            Stat::make('Sisa kuota', $p['quota'] ?? '-')
                ->description($p['messages'] !== null ? number_format($p['messages'], 0, ',', '.').' pesan terkirim' : '')
                ->color(is_numeric($p['quota']) && (int) $p['quota'] < 100 ? 'warning' : null)
                ->icon(Heroicon::OutlinedChatBubbleLeftRight),
            Stat::make('Kedaluwarsa', $p['expired'] ?? '-')->icon(Heroicon::OutlinedCalendarDays),
        ];
    }
}
