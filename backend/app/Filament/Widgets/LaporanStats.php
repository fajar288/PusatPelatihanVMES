<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Kartu ringkasan di atas halaman /admin/laporan (pendaftaran, kelas, pendapatan, piutang). */
class LaporanStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    /** 1 kolom di ponsel, 2 di tablet/layar sempit, 4 di layar lebar agar nominal rupiah tidak terpotong. */
    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $o = app(ReportService::class)->overview();
        $rev = $o['revenue'];
        $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        $statuses = collect($o['registrations_by_status'])
            ->filter(fn ($st) => $st['count'] > 0)
            ->map(fn ($st) => $st['count'].' '.mb_strtolower($st['label']))
            ->implode(', ');

        $monthly = array_values(array_slice($rev['by_month'], -6, null, true));

        return [
            Stat::make('Pendaftaran', (string) $o['registrations_total'])
                ->description($statuses !== '' ? $statuses : 'Belum ada pendaftaran')
                ->icon(Heroicon::OutlinedClipboardDocumentList),
            Stat::make('Kelas mendatang', (string) $o['upcoming_classes'])
                ->description($o['enrollments_active'].' peserta aktif')
                ->icon(Heroicon::OutlinedCalendarDays),
            Stat::make('Pendapatan terverifikasi', $rupiah($rev['verified']))
                ->description('Menunggu verifikasi '.$rupiah($rev['pending']))
                ->descriptionIcon($rev['pending'] > 0 ? Heroicon::OutlinedClock : null)
                ->descriptionColor($rev['pending'] > 0 ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->chart(count($monthly) > 1 ? $monthly : null),
            Stat::make('Piutang (belum dibayar)', $rupiah($rev['outstanding']))
                ->description($rev['outstanding'] > 0 ? 'Tagihan belum dibayar / ditolak' : 'Tidak ada tagihan tertunggak')
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->color($rev['outstanding'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
