<?php

namespace App\Filament\Widgets\Executive;

use App\Models\AiInsightFeedback;
use App\Services\AiDemandForecastService;
use App\Services\AiInsightService;
use App\Support\Ai\Forecast\Forecaster;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Prakiraan peserta terdaftar 3 bulan ke depan (metode statistik, tanpa LLM): total, segmen, bidang, dan program
 * terpopuler, dengan rentang ketidakpastian dan galat backtest dibanding baseline bulan yang sama tahun lalu.
 * Tidak mengikuti filter periode dashboard (selalu dari riwayat bulanan lengkap).
 */
class DemandForecastTable extends TableWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 22;

    protected int|string|array $columnSpan = 'full';

    private const SCOPES = ['total' => 'Total', 'segment' => 'Segmen', 'category' => 'Bidang', 'package' => 'Program'];

    private static function num(?float $v, int $dec = 0): string
    {
        return $v === null ? '-' : number_format($v, $dec, ',', '.');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $svc = app(AiDemandForecastService::class);
        $insight = $svc->status()['available'] ? $svc->latest() : null;
        if (! $insight) {
            return [];
        }
        $out = (array) $insight->output;

        return array_map(fn (array $s) => [
            'key' => $s['scope'].':'.$s['key'],
            'scope' => self::SCOPES[$s['scope']] ?? $s['scope'],
            'label' => $s['label'],
            'recent' => implode(', ', array_values((array) $s['recent'])),
            'total12' => $s['total_12m'],
            'f' => array_map(fn ($p) => self::num($p['value']).' ('.self::num($p['low']).'-'.self::num($p['high']).')', $s['points']),
            'method' => $s['method_label'],
            'error' => 'MAE '.self::num($s['backtest']['mae'], 1)
                .($s['backtest']['mape'] !== null ? ', MAPE '.self::num($s['backtest']['mape'], 0).'%' : ''),
            // Bila metode terpilih adalah baseline itu sendiri, tidak ada yang dibandingkan.
            'baseline' => $s['method'] === Forecaster::BASELINE ? 'Metode terbaik adalah baseline' : match ($s['backtest']['beats_baseline']) {
                true => 'Lebih baik/sama (baseline MAE '.self::num($s['backtest']['baseline_mae'], 1).')',
                false => 'Kalah (baseline MAE '.self::num($s['backtest']['baseline_mae'], 1).')',
                null => 'Belum dapat dinilai',
            },
        ], (array) ($out['series'] ?? []));
    }

    public function table(Table $table): Table
    {
        $svc = app(AiDemandForecastService::class);
        $status = $svc->status();
        $insight = $status['available'] ? $svc->latest() : null;
        $out = (array) ($insight?->output ?? []);
        $month = fn (int $i) => isset($out['months'][$i]) ? Carbon::createFromFormat('Y-m-d', $out['months'][$i].'-01')->locale('id')->translatedFormat('M Y') : 'Bulan '.($i + 1);
        $description = match (true) {
            ! $status['available'] => 'Belum tersedia: '.$status['reason'].'.',
            $insight === null => 'Belum ada prakiraan. Dihitung setiap tanggal 1 dari riwayat peserta per bulan (minimal '.Forecaster::MIN_HISTORY.' bulan lengkap).',
            default => 'Prakiraan peserta terdaftar dengan rentang ketidakpastian sekitar 80%, dari riwayat '.$out['history_months'].' bulan sampai '.$out['generated_from']
                .'. Metode statistik dipilih per baris lewat uji bulan-bulan terakhir; baseline = bulan yang sama tahun lalu.'
                .(($out['data_ready'] ?? false) ? '' : ' Data belum cukup: riwayat masih pendek, perlakukan angka sebagai gambaran kasar.'),
        };
        $mine = $insight ? AiInsightFeedback::query()->where('ai_insight_id', $insight->id)->where('user_id', auth()->id())->value('helpful') : null;

        return $table
            ->heading('Prakiraan peserta 3 bulan ke depan')
            ->description($description)
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $rows = collect($this->rows());

                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage)->keyBy('key')->all(), $rows->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('label')->label('Lingkup')->description(fn (array $record) => $record['scope'])->wrap(),
                TextColumn::make('recent')->label('6 bulan terakhir')->description(fn (array $record) => '12 bulan: '.$record['total12']),
                TextColumn::make('f0')->label($month(0))->state(fn (array $record) => $record['f'][0] ?? '-')->badge()->color('info'),
                TextColumn::make('f1')->label($month(1))->state(fn (array $record) => $record['f'][1] ?? '-')->badge()->color('gray'),
                TextColumn::make('f2')->label($month(2))->state(fn (array $record) => $record['f'][2] ?? '-')->badge()->color('gray'),
                TextColumn::make('method')->label('Metode')->wrap()->description(fn (array $record) => $record['error']),
                TextColumn::make('baseline')->label('Dibanding baseline')->wrap(),
            ])
            ->headerActions($insight ? [
                Action::make('label')->label('Prakiraan, dibuat AI')->badge()->color('info')->disabled(),
                Action::make('helpful')->label($mine === true ? 'Membantu (dipilih)' : 'Membantu')->icon(Heroicon::OutlinedHandThumbUp)->color($mine === true ? 'success' : 'gray')->outlined($mine !== true)->size('sm')
                    ->action(fn () => $this->feedback($insight->id, true)),
                Action::make('not_helpful')->label($mine === false ? 'Tidak membantu (dipilih)' : 'Tidak membantu')->icon(Heroicon::OutlinedHandThumbDown)->color($mine === false ? 'danger' : 'gray')->outlined($mine !== false)->size('sm')
                    ->action(fn () => $this->feedback($insight->id, false)),
            ] : [])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading($status['available'] ? 'Belum ada prakiraan' : 'Belum tersedia')
            ->emptyStateDescription($status['available'] ? 'Jalankan php artisan ai:demand-forecast atau tunggu jadwal bulanan.' : 'Fitur prakiraan permintaan belum dinyalakan.');
    }

    private function feedback(int $insightId, bool $helpful): void
    {
        $insight = app(AiDemandForecastService::class)->latest();
        if ($insight && $insight->id === $insightId) {
            app(AiInsightService::class)->feedback($insight, auth()->user(), $helpful);
            Notification::make()->title('Terima kasih, umpan balik dicatat')->success()->send();
        }
    }
}
