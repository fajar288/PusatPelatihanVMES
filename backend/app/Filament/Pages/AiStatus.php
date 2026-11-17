<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Support\AiAccess;
use App\Services\AiGovernance;
use App\Services\AiReadiness;
use App\Support\Ai\AiUseCases;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * /admin/ai/status: status setiap use case AI (flag, API key, jeda, batas harian, kesiapan data) + tombol jeda/lanjutkan.
 * Menyalakan fitur hanya lewat env; dari sini admin (ai.manage) hanya bisa menjeda atau melanjutkan. Tercatat di audit_logs.
 */
class AiStatus extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Status fitur AI';

    protected static ?string $title = 'Status fitur AI';

    protected static ?string $slug = 'ai/status';

    protected string $view = 'filament.pages.ai-status';

    public static function canAccess(): bool
    {
        return AiAccess::canReview();
    }

    public function getSubheading(): ?string
    {
        return config('ai.enabled')
            ? 'Fitur AI diizinkan secara global. Setiap use case tetap butuh flag sendiri.'
            : 'Semua fitur AI mati (AI_ENABLED=false). Proses manual dan aturan biasa tetap berjalan.';
    }

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        $governance = app(AiGovernance::class);
        $readiness = app(AiReadiness::class);

        return collect(AiUseCases::all())->mapWithKeys(function (array $uc) use ($governance, $readiness) {
            $status = $governance->status($uc['key']);
            $ready = $readiness->report($uc['key']);

            return [$uc['key'] => $uc + [
                'status' => $status,
                'ready' => $ready,
            ]];
        })->all();
    }

    /** Beberapa baris keterangan di bawah nilai kolom (teks di-escape). @param list<string|null> $lines */
    private static function lines(array $lines): HtmlString
    {
        return new HtmlString(collect($lines)->filter()->map(fn ($l) => e($l))->implode('<br>'));
    }

    public function table(Table $table): Table
    {
        $num = fn ($n) => number_format((int) $n, 0, ',', '.');

        return $table
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('label')->label('Use case')->weight('bold')->wrap()
                    ->description(fn (array $record) => $record['description']),
                TextColumn::make('state')->label('Status & metode')->badge()->wrap()
                    ->state(fn (array $record) => $record['status']['available'] ? 'Aktif' : 'Belum tersedia')
                    ->color(fn (array $record) => $record['status']['available'] ? 'success' : ($record['status']['paused'] ? 'warning' : 'gray'))
                    ->description(fn (array $record) => self::lines([
                        $record['status']['reason_label'],
                        (AiUseCases::METHODS[$record['method']] ?? $record['method']).($record['model'] ? ': '.$record['model'].', effort '.$record['effort'] : ''),
                    ])),
                TextColumn::make('usage')->label('Pemakaian hari ini')->alignEnd()
                    ->state(fn (array $record) => $record['method'] === AiUseCases::METHOD_LLM
                        ? $num($record['status']['usage_today']['calls']).($record['daily_calls'] ? ' / '.$num($record['daily_calls']) : '').' panggilan'
                        : 'Tanpa biaya model')
                    ->description(fn (array $record) => $record['method'] === AiUseCases::METHOD_LLM
                        ? $num($record['status']['usage_today']['tokens']).($record['daily_tokens'] ? ' / '.$num($record['daily_tokens']) : '').' token'
                        : null),
                TextColumn::make('readiness')->label('Kesiapan data')->wrap()
                    ->state(fn (array $record) => $record['ready']['checks'] === [] ? 'Tidak ada ambang' : ($record['ready']['ready'] ? 'Data cukup' : 'Data belum cukup'))
                    ->badge()->color(fn (array $record) => $record['ready']['ready'] ? 'success' : 'warning')
                    ->description(fn (array $record) => $record['ready']['checks'] === [] ? null : self::lines(collect($record['ready']['checks'])
                        ->map(fn ($c) => $c['label'].': '.$num($c['actual']).' dari '.$num($c['required']))->all())),
                TextColumn::make('review')->label('Tinjauan & metrik')->wrap()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => AiUseCases::REVIEWS[$state] ?? $state)
                    ->description(fn (array $record) => implode(', ', $record['metrics']).'. Pembanding: '.$record['baseline']),
            ])
            ->recordActions([
                Action::make('pause')->label('Jeda')->icon(Heroicon::OutlinedPause)->color('warning')
                    ->visible(fn (array $record) => ! $record['status']['paused'] && AiAccess::canManage())
                    ->modalHeading(fn (array $record) => 'Jeda '.$record['label'])
                    ->modalDescription('Use case berhenti memanggil model dan kembali ke proses manual sampai dilanjutkan. Tindakan ini tercatat di log audit.')
                    ->schema([TextInput::make('note')->label('Alasan (opsional)')->maxLength(255)])
                    ->action(function (array $data, array $record): void {
                        app(AiGovernance::class)->pause($record['key'], auth()->user(), $data['note'] ?? null);
                        Notification::make()->title($record['label'].' dijeda')->success()->send();
                    }),
                Action::make('resume')->label('Lanjutkan')->icon(Heroicon::OutlinedPlay)->color('success')
                    ->visible(fn (array $record) => $record['status']['paused'] && AiAccess::canManage())
                    ->requiresConfirmation()
                    ->modalDescription('Jeda dicabut. Use case hanya berjalan lagi bila flag env dan syarat lain juga terpenuhi.')
                    ->action(function (array $record): void {
                        app(AiGovernance::class)->resume($record['key'], auth()->user());
                        Notification::make()->title($record['label'].' dilanjutkan')->success()->send();
                    }),
            ]);
    }
}
