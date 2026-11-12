<?php

namespace App\Filament\Widgets;

use App\Models\NotificationDelivery;
use App\Support\WhatsApp\WhatsAppSender;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Ringkasan Notification Engine 7 hari terakhir di atas halaman Pengiriman notifikasi. */
class NotificationDeliveryStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $since = now()->subDays(7);
        $counts = NotificationDelivery::query()->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $total = (int) $counts->sum();
        $ok = (int) ($counts['sent'] ?? 0) + (int) ($counts['delivered'] ?? 0) + (int) ($counts['read'] ?? 0);
        $done = $ok + (int) ($counts['failed'] ?? 0);
        $retrying = NotificationDelivery::query()->where('status', 'queued')->where('attempts', '>', 0)->count();
        $scheduled = NotificationDelivery::query()->where('status', 'queued')->where('scheduled_at', '>', now())->count();
        $inApp = NotificationDelivery::query()->where('created_at', '>=', $since)->where('channel', 'database');
        $inAppTotal = (clone $inApp)->count();
        $read = (clone $inApp)->where('status', 'read')->count();

        return [
            Stat::make('Pengiriman 7 hari', (string) $total)
                ->description($done > 0 ? 'Berhasil '.round($ok / $done * 100, 1).'%' : 'Belum ada pengiriman')
                ->icon(Heroicon::OutlinedPaperAirplane),
            Stat::make('Gagal', (string) ($counts['failed'] ?? 0))
                ->description($retrying > 0 ? "$retrying menunggu retry" : 'Tidak ada yang menunggu retry')
                ->color(($counts['failed'] ?? 0) > 0 ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedExclamationTriangle),
            Stat::make('Terjadwal', (string) $scheduled)->description('Antre untuk dikirim nanti')->icon(Heroicon::OutlinedClock),
            Stat::make('Dibaca (aplikasi)', $inAppTotal > 0 ? round($read / $inAppTotal * 100, 1).'%' : '-')
                ->description(app(WhatsAppSender::class)->enabled() ? 'WhatsApp aktif (driver '.app(WhatsAppSender::class)->provider().')' : 'WhatsApp nonaktif (penyedia ditunda)')
                ->icon(Heroicon::OutlinedEye),
        ];
    }
}
