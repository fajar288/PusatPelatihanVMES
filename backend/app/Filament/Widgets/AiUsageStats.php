<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Ringkasan pemakaian AI: hari ini dan 30 hari terakhir (panggilan, token, perkiraan biaya, penolakan karena batas). */
class AiUsageStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $since = fn ($from) => AiUsageRecord::query()->where('created_at', '>=', $from);
        $sum = fn ($from) => $since($from)->whereIn('status', ['ok', 'refused', 'error'])
            ->selectRaw('COUNT(*) AS calls, COALESCE(SUM(input_tokens + output_tokens + cache_read_tokens + cache_write_tokens), 0) AS tokens, COALESCE(SUM(cost_usd), 0) AS cost')
            ->first();
        $today = $sum(today());
        $month = $sum(now()->subDays(30));
        $denied = $since(now()->subDays(30))->whereIn('status', ['limited', 'paused'])->count();
        $failed = $since(now()->subDays(30))->whereIn('status', ['error', 'refused'])->count();
        $num = fn ($n) => number_format((int) $n, 0, ',', '.');
        $usd = fn ($v) => 'US$ '.number_format((float) $v, 2, ',', '.');

        return [
            Stat::make('Panggilan hari ini', $num($today?->calls))
                ->description($num($today?->tokens).' token, '.$usd($today?->cost))
                ->icon(Heroicon::OutlinedBolt),
            Stat::make('Biaya 30 hari', $usd($month?->cost))
                ->description($num($month?->calls).' panggilan, '.$num($month?->tokens).' token')
                ->icon(Heroicon::OutlinedCurrencyDollar),
            Stat::make('Gagal / ditolak model', $num($failed))
                ->description('30 hari terakhir; fitur kembali ke proses manual')
                ->color($failed > 0 ? 'warning' : null)
                ->icon(Heroicon::OutlinedExclamationTriangle),
            Stat::make('Tertahan batas / jeda', $num($denied))
                ->description('Permintaan yang tidak dikirim ke model (30 hari)')
                ->icon(Heroicon::OutlinedPauseCircle),
        ];
    }
}
