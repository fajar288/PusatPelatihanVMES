<?php

namespace App\Filament\Resources\AiInsights;

use App\Filament\NavigationGroup;
use App\Filament\Resources\AiInsights\Pages\ListAiInsights;
use App\Filament\Support\AiAccess;
use App\Filament\Support\EnterpriseFields;
use App\Models\AiInsight;
use App\Services\AiInsightService;
use App\Support\Ai\AiUseCases;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Daftar insight AI + antrean tinjauan (tab "Menunggu tinjauan"). Hanya baca; satu-satunya perubahan adalah keputusan
 * tinjauan (setujui/tolak) lewat AiInsightService, tercatat di audit_logs. Izin ai.review (atau ai.manage untuk melihat).
 */
class AiInsightResource extends Resource
{
    protected static ?string $model = AiInsight::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Insight AI';

    protected static ?string $modelLabel = 'Insight AI';

    protected static ?string $pluralModelLabel = 'Insight AI';

    protected static ?string $slug = 'ai/insight';

    public static function canViewAny(): bool
    {
        return AiAccess::canReview();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = AiInsight::query()->pendingReview()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Menunggu tinjauan';
    }

    /** @return array<string, string> */
    public static function useCaseOptions(): array
    {
        return collect(AiUseCases::all())->mapWithKeys(fn ($u) => [$u['key'] => $u['label']])->all();
    }

    public static function table(Table $table): Table
    {
        $labels = self::useCaseOptions();

        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['reviewer:id,name', 'organization:id,code,legal_name'])->withCount('feedback'))
            ->emptyStateHeading('Belum ada insight AI')
            ->emptyStateDescription('Semua fitur AI masih mati. Insight akan muncul di sini setelah fitur dinyalakan dan datanya cukup.')
            ->columns([
                TextColumn::make('summary')->label('Insight')->state(fn (AiInsight $record) => self::summary($record))->wrap()->limit(160)
                    ->grow()->extraHeaderAttributes(['style' => 'min-width: 22rem'])
                    ->description(fn (AiInsight $record) => ($labels[$record->use_case] ?? $record->use_case).' · '.self::subjectLabel($record)
                        .' · '.$record->created_at?->format('d M Y H:i')),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('score')->label('Skor')->numeric(2)->placeholder('-')->sortable()
                    ->description(fn (AiInsight $record) => $record->confidence !== null ? 'keyakinan '.round($record->confidence * 100).'%' : null),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => AiInsight::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        AiInsight::STATUS_APPROVED => 'success', AiInsight::STATUS_REJECTED => 'danger', AiInsight::STATUS_DRAFT => 'warning', default => 'gray',
                    })
                    ->description(fn (AiInsight $record) => $record->reviewer ? 'oleh '.$record->reviewer->name : null),
                TextColumn::make('feedback_count')->label('Umpan balik')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model_version')->label('Model · prompt')->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (AiInsight $record) => $record->prompt_version),
            ])
            ->filters([
                SelectFilter::make('use_case')->label('Use case')->options($labels),
            ])
            ->recordActions([
                Action::make('detail')->label('Detail')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading('Detail insight AI')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->schema(self::detailSchema()),
                Action::make('approve')->label('Setujui')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (AiInsight $record) => $record->status === AiInsight::STATUS_DRAFT && AiAccess::canDecide())
                    ->modalHeading('Setujui insight AI')
                    ->modalDescription('Insight yang disetujui boleh tampil ke pengguna sesuai aturan use case-nya. Data resmi (nilai, kelulusan, sertifikat, pembayaran) tidak berubah.')
                    ->schema(self::detailSchema())
                    ->action(fn (AiInsight $record, Action $action) => EnterpriseFields::attempt($action, fn () => app(AiInsightService::class)->approve($record, auth()->user()), 'Insight disetujui')),
                Action::make('reject')->label('Tolak')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (AiInsight $record) => in_array($record->status, [AiInsight::STATUS_DRAFT, AiInsight::STATUS_APPROVED], true) && AiAccess::canDecide())
                    ->modalHeading('Tolak insight AI')
                    ->schema([
                        ...self::detailSchema(),
                        Textarea::make('rejection_reason')->label('Alasan penolakan')->required()->minLength(10)->rows(3)
                            ->helperText('Dipakai sebagai bahan evaluasi kualitas use case ini.'),
                    ])
                    ->action(fn (array $data, AiInsight $record, Action $action) => EnterpriseFields::attempt($action, fn () => app(AiInsightService::class)->reject($record, auth()->user(), (string) $data['rejection_reason']), 'Insight ditolak')),
            ]);
    }

    /** @return list<Component> */
    private static function detailSchema(): array
    {
        $labels = self::useCaseOptions();

        return [
            TextEntry::make('ai_label')->hiddenLabel()->badge()->color('info')->state('Dibuat AI'),
            TextEntry::make('use_case_label')->label('Use case')->state(fn (AiInsight $record) => ($labels[$record->use_case] ?? $record->use_case).' · '.self::subjectLabel($record)),
            KeyValueEntry::make('output_flat')->label('Keluaran')->keyLabel('Bagian')->valueLabel('Isi')
                ->state(fn (AiInsight $record) => collect(Arr::dot($record->output ?? []))->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all()),
            TextEntry::make('meta')->label('Asal')->state(fn (AiInsight $record) => new HtmlString(e(collect([
                'model '.($record->model_version ?? '-'),
                'prompt '.($record->prompt_version ?? '-'),
                $record->score !== null ? 'skor '.$record->score : null,
                $record->confidence !== null ? 'keyakinan '.round($record->confidence * 100).'%' : null,
            ])->filter()->implode(' · ')))),
            TextEntry::make('data_reference_hash')->label('Referensi data')
                ->state(fn (AiInsight $record) => count($record->data_reference['items'] ?? []).' sumber, hash '.substr((string) ($record->data_reference['hash'] ?? '-'), 0, 16))
                ->helperText('Hanya ID dan hash data sumber yang disimpan, bukan salinan data pribadi.'),
            TextEntry::make('rejection_reason')->label('Alasan penolakan')->visible(fn (AiInsight $record) => filled($record->rejection_reason)),
        ];
    }

    public static function subjectLabel(AiInsight $record): string
    {
        $parts = [];
        if ($record->subject_type) {
            $parts[] = class_basename($record->subject_type).' #'.$record->subject_id;
        }
        if ($record->organization) {
            $parts[] = $record->organization->code;
        }

        return $parts ? implode(' · ', $parts) : 'Umum';
    }

    /** Teks ringkas dari keluaran: kunci "summary"/"text"/"reason" bila ada, selain itu nilai teks pertama. */
    public static function summary(AiInsight $record): string
    {
        $out = (array) ($record->output ?? []);
        foreach (['summary', 'text', 'reason', 'title'] as $key) {
            if (is_string($out[$key] ?? null) && $out[$key] !== '') {
                return $out[$key];
            }
        }
        $first = collect(Arr::dot($out))->first(fn ($v) => is_string($v) && $v !== '');

        return is_string($first) ? $first : '-';
    }

    public static function getPages(): array
    {
        return ['index' => ListAiInsights::route('/')];
    }
}
