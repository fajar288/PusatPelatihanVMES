<?php

namespace App\Filament\Resources\AiUsageRecords;

use App\Filament\NavigationGroup;
use App\Filament\Resources\AiInsights\AiInsightResource;
use App\Filament\Resources\AiUsageRecords\Pages\ListAiUsageRecords;
use App\Filament\Support\AiAccess;
use App\Models\AiUsageRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Pemakaian model AI per panggilan: token, perkiraan biaya, latensi, status (termasuk penolakan karena batas/jeda). */
class AiUsageRecordResource extends Resource
{
    protected static ?string $model = AiUsageRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Pemakaian & biaya';

    protected static ?string $modelLabel = 'Pemakaian AI';

    protected static ?string $pluralModelLabel = 'Pemakaian & biaya AI';

    protected static ?string $slug = 'ai/pemakaian';

    public static function canViewAny(): bool
    {
        return AiAccess::canReview();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $labels = AiInsightResource::useCaseOptions();
        $num = fn ($n) => number_format((int) $n, 0, ',', '.');

        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada pemakaian')
            ->emptyStateDescription('Setiap panggilan model AI tercatat di sini beserta token dan perkiraan biayanya.')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('use_case')->label('Use case')->formatStateUsing(fn ($state) => $labels[$state] ?? $state),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => AiUsageRecord::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'ok' => 'success', 'refused', 'limited', 'paused' => 'warning', default => 'danger',
                    })
                    ->description(fn (AiUsageRecord $record) => $record->error),
                TextColumn::make('tokens')->label('Token (masuk / keluar)')->alignEnd()
                    ->state(fn (AiUsageRecord $record) => $num($record->input_tokens + $record->cache_read_tokens + $record->cache_write_tokens).' / '.$num($record->output_tokens))
                    ->description(fn (AiUsageRecord $record) => $record->cache_read_tokens > 0 ? $num($record->cache_read_tokens).' dari cache' : null),
                TextColumn::make('cost_usd')->label('Perkiraan biaya')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => 'US$ '.number_format((float) $state, 4, ',', '.')),
                TextColumn::make('latency_ms')->label('Durasi')->alignEnd()->placeholder('-')->toggleable()
                    ->formatStateUsing(fn ($state) => number_format($state / 1000, 1, ',', '.').' dtk'),
                TextColumn::make('model')->label('Model · prompt')->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (AiUsageRecord $record) => $record->prompt_version),
            ])
            ->filters([
                SelectFilter::make('use_case')->label('Use case')->options($labels),
                SelectFilter::make('status')->options(AiUsageRecord::STATUSES),
                Filter::make('today')->label('Hari ini')->query(fn (Builder $query) => $query->where('created_at', '>=', today())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAiUsageRecords::route('/')];
    }
}
