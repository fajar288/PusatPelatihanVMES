<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PaymentTransactions\PaymentTransactionResource;
use App\Services\ReconciliationService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Ringkasan rekonsiliasi: bukti menunggu verifikasi, kurang/lebih bayar, mutasi belum cocok. */
class ReconciliationStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $s = app(ReconciliationService::class)->summary();
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        return [
            Stat::make('Menunggu verifikasi', (string) $s['pending'])
                ->description($s['pending_without_statement'].' belum tertaut mutasi')->icon(Heroicon::OutlinedClock)
                ->url(PaymentTransactionResource::getUrl('index')),
            Stat::make('Kurang bayar (invoice terbuka)', (string) $s['underpaid'])
                ->description('Sudah dibayar sebagian')->icon(Heroicon::OutlinedExclamationTriangle)->color($s['underpaid'] ? 'warning' : 'gray')
                ->url(InvoiceResource::getUrl('index')),
            Stat::make('Lebih bayar', (string) $s['overpaid'])
                ->description('Transaksi dengan selisih positif')->icon(Heroicon::OutlinedArrowTrendingUp)->color($s['overpaid'] ? 'info' : 'gray')
                ->url(PaymentTransactionResource::getUrl('index')),
            Stat::make('Mutasi belum cocok', (string) $s['unmatched_lines'])
                ->description($rp($s['unmatched_amount']))->icon(Heroicon::OutlinedQuestionMarkCircle),
        ];
    }
}
