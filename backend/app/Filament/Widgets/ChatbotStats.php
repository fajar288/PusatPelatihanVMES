<?php

namespace App\Filament\Widgets;

use App\Models\ChatLog;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** KPI chatbot 30 hari terakhir (Measurable AI): volume, tidak terjawab, umpan balik, tinjauan, latensi, token. */
class ChatbotStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected ?string $heading = '30 hari terakhir';

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $q = fn () => ChatLog::query()->where('created_at', '>=', now()->subDays(30));
        $total = $q()->count();
        $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100).'%' : '-';

        $unresolved = $q()->where('status', 'unresolved')->count();
        $failed = $q()->whereIn('status', ['error', 'refused'])->count();
        $up = $q()->where('feedback', 'up')->count();
        $down = $q()->where('feedback', 'down')->count();
        $reviewed = $q()->whereNotNull('review')->count();
        $correct = $q()->where('review', 'correct')->count();
        $latency = (int) $q()->where('status', '!=', 'error')->avg('latency_ms');
        $in = (int) $q()->sum('input_tokens');
        $cached = (int) $q()->sum('cache_read_tokens');
        $out = (int) $q()->sum('output_tokens');
        $num = fn (int $n) => number_format($n, 0, ',', '.');

        return [
            Stat::make('Pertanyaan', $num($total))
                ->description($q()->distinct()->count('conversation_id').' percakapan')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight),
            Stat::make('Tidak terjawab', $pct($unresolved, $total))
                ->description($unresolved.' dialihkan ke admin, '.$failed.' gagal/ditolak')
                ->color($total > 0 && $unresolved / $total > 0.3 ? 'warning' : null)
                ->icon(Heroicon::OutlinedQuestionMarkCircle),
            Stat::make('Umpan balik positif', $pct($up, $up + $down))
                ->description("👍 {$up} · 👎 {$down}")
                ->icon(Heroicon::OutlinedHandThumbUp),
            Stat::make('Tinjauan admin: tepat', $pct($correct, $reviewed))
                ->description($reviewed.' jawaban ditinjau')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck),
            Stat::make('Rata-rata waktu jawab', $latency > 0 ? number_format($latency / 1000, 1, ',', '.').' dtk' : '-')
                ->icon(Heroicon::OutlinedClock),
            Stat::make('Token', $num($in + $cached + $out))
                ->description('input '.$num($in).' · cache '.$num($cached).' · output '.$num($out))
                ->icon(Heroicon::OutlinedCpuChip),
        ];
    }
}
