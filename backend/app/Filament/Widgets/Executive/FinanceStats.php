<?php

namespace App\Filament\Widgets\Executive;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Pendapatan, invoice, pembayaran & piutang (PRD §5.13 butir 2). */
class FinanceStats extends StatsOverviewWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Keuangan';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $fi = $this->analytics()['finance'];

        return [
            Stat::make('Pendapatan terverifikasi', self::rupiah($fi['revenue'], true))
                ->description(self::lines($fi['payments_verified'].' pembayaran', self::rupiah($fi['revenue'])))
                ->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->chart(array_values($fi['revenue_by_month'])),
            Stat::make('Invoice diterbitkan', (string) $fi['invoices'])
                ->description(self::lines('Nilai '.self::rupiah($fi['invoiced_amount'], true), 'Tertagih '.self::pct($fi['collection_rate'])))
                ->icon(Heroicon::OutlinedDocumentText),
            Stat::make('Menunggu verifikasi', self::rupiah($fi['pending_verification'], true))
                ->description($fi['pending_count'].' bukti bayar belum diverifikasi')
                ->icon(Heroicon::OutlinedClock)->color($fi['pending_count'] > 0 ? 'warning' : 'gray'),
            Stat::make('Piutang', self::rupiah($fi['outstanding'], true))
                ->description(self::lines($fi['outstanding_count'].' invoice belum lunas', 'Jatuh tempo '.self::rupiah($fi['overdue'], true).' ('.$fi['overdue_count'].' invoice)'))
                ->descriptionIcon($fi['overdue_count'] > 0 ? Heroicon::OutlinedExclamationTriangle : null)
                ->icon(Heroicon::OutlinedReceiptPercent)->color($fi['overdue_count'] > 0 ? 'danger' : 'gray'),
        ];
    }
}
