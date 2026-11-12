<?php

namespace App\Filament\Resources\ChatLogs;

use App\Filament\NavigationGroup;
use App\Filament\Resources\ChatLogs\Pages\ListChatLogs;
use App\Models\ChatLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Log chatbot publik (Measurable AI): tanya-jawab, status, umpan balik pengunjung, tinjauan admin, token & latensi.
 * Hanya baca + aksi tinjau. Izin cms.manage (konten website) atau analytics.view.
 */
class ChatLogResource extends Resource
{
    protected static ?string $model = ChatLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::LandingPage;

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Chatbot AI';

    protected static ?string $modelLabel = 'Log chatbot';

    protected static ?string $pluralModelLabel = 'Log chatbot';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('cms.manage') || $u?->hasPermission('analytics.view') || $u?->isSuperAdmin());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada percakapan')
            ->emptyStateDescription(config('chatbot.enabled') && filled(config('chatbot.api_key'))
                ? 'Pertanyaan pengunjung ke asisten virtual akan tercatat di sini.'
                : 'Chatbot belum aktif. Isi ANTHROPIC_API_KEY dan set CHATBOT_ENABLED=true di .env backend.')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('question')->label('Pertanyaan')->searchable()->limit(80)->wrap()
                    ->description(fn (ChatLog $record) => str($record->answer)->limit(120)->toString()),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => ChatLog::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'answered' => 'success', 'unresolved' => 'warning', default => 'danger'
                    }),
                TextColumn::make('feedback')->label('Pengunjung')->formatStateUsing(fn ($state) => $state === 'up' ? '👍' : '👎')->placeholder('-'),
                TextColumn::make('review')->label('Tinjauan')->badge()->placeholder('-')
                    ->formatStateUsing(fn ($state) => ChatLog::REVIEWS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'correct' => 'success', 'incorrect' => 'danger', default => 'warning'
                    }),
                TextColumn::make('latency_ms')->label('Waktu jawab')->formatStateUsing(fn ($state) => number_format($state / 1000, 1, ',', '.').' dtk')->sortable()->toggleable(),
                TextColumn::make('output_tokens')->label('Token (in/out)')->toggleable()
                    ->formatStateUsing(fn (ChatLog $record) => ($record->input_tokens + $record->cache_read_tokens + $record->cache_write_tokens).' / '.$record->output_tokens),
                TextColumn::make('model')->label('Model · prompt')->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (ChatLog $record) => $record->prompt_version),
            ])
            ->filters([
                SelectFilter::make('status')->options(ChatLog::STATUSES),
                SelectFilter::make('feedback')->label('Umpan balik')->options(['up' => '👍 Membantu', 'down' => '👎 Tidak membantu']),
                Filter::make('unreviewed')->label('Belum ditinjau')->query(fn (Builder $query) => $query->whereNull('review')),
                Filter::make('today')->label('Hari ini')->query(fn (Builder $query) => $query->whereDate('created_at', today())),
            ])
            ->recordActions([
                Action::make('review')->label('Tinjau')->icon('heroicon-o-clipboard-document-check')->color('gray')
                    ->modalHeading('Tinjau jawaban chatbot')
                    ->fillForm(fn (ChatLog $record) => ['review' => $record->review, 'review_note' => $record->review_note])
                    ->schema([
                        TextEntry::make('q')->label('Pertanyaan')->state(fn (ChatLog $record) => $record->question),
                        TextEntry::make('a')->label('Jawaban')->state(fn (ChatLog $record) => $record->answer)->markdown(),
                        TextEntry::make('meta')->label('Detail')->state(fn (ChatLog $record) => trim(
                            (ChatLog::STATUSES[$record->status] ?? $record->status).' · '.$record->model.' · prompt '.$record->prompt_version
                            .($record->error ? ' · '.$record->error : '')
                        )),
                        Select::make('review')->label('Penilaian')->options(ChatLog::REVIEWS)->required()->native(false),
                        Textarea::make('review_note')->label('Catatan (mis. data yang perlu dilengkapi di website)')->rows(2),
                    ])
                    ->action(fn (array $data, ChatLog $record) => $record->update([
                        'review' => $data['review'], 'review_note' => $data['review_note'] ?? null, 'reviewed_by' => auth()->id(),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListChatLogs::route('/')];
    }
}
