<?php

namespace App\Filament\Resources\SurveyResponses;

use App\Filament\NavigationGroup;
use App\Filament\Resources\SurveyResponses\Pages\ListSurveyResponses;
use App\Filament\Support\AiAccess;
use App\Models\FeedbackLabel;
use App\Models\SurveyResponse;
use App\Services\AiFeedbackService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Jawaban survei evaluasi pasca-kelas. Komentar tampil dengan klasifikasi AI (label "Dibuat AI") bila ada, dan bisa
 * diberi label manual (izin ai.review) sebagai pembanding akurasi. Izin lihat: monitoring.manage atau ai.review.
 */
class SurveyResponseResource extends Resource
{
    protected static ?string $model = SurveyResponse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsDelivery;

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Survei evaluasi';

    protected static ?string $modelLabel = 'Jawaban survei';

    protected static ?string $pluralModelLabel = 'Survei evaluasi';

    protected static ?string $slug = 'survei-evaluasi';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('monitoring.manage') || AiAccess::canReview());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $ai = app(AiFeedbackService::class);

        return $table
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['template', 'schedule.package:id,title', 'enrollment:id,participant_name', 'labels']))
            ->emptyStateHeading('Belum ada survei')
            ->emptyStateDescription('Undangan survei dikirim otomatis setelah kelas selesai.')
            ->columns([
                TextColumn::make('schedule.code')->label('Kelas')->description(fn (SurveyResponse $record) => $record->schedule?->title ?? $record->schedule?->package?->title)->searchable(),
                TextColumn::make('enrollment.participant_name')->label('Peserta')->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => SurveyResponse::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'submitted' ? 'success' : 'gray')
                    ->description(fn (SurveyResponse $record) => $record->submitted_at?->format('d M Y')),
                TextColumn::make('average')->label('Rata-rata')->alignEnd()->state(fn (SurveyResponse $record) => $record->averageScore())->placeholder('-')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').' / 5'),
                TextColumn::make('comments')->label('Komentar')->wrap()
                    ->state(function (SurveyResponse $record) use ($ai) {
                        $cls = $ai->classifications([$record->id]);
                        $lines = [];
                        foreach ($record->comments() as $key => $text) {
                            $c = $cls->get($record->id.':'.$key);
                            $l = $record->labels->firstWhere('question_key', $key);
                            $lines[] = '<b>'.e($text).'</b>'
                                .($c ? '<br><small>Dibuat AI: '.e(AiFeedbackService::SENTIMENTS[$c['sentiment']] ?? $c['sentiment']).' · '.e(AiFeedbackService::CATEGORIES[$c['category']] ?? $c['category']).' · '.e($c['theme']).'</small>' : '')
                                .($l ? '<br><small>Label manual: '.e(AiFeedbackService::SENTIMENTS[$l->sentiment] ?? $l->sentiment).' · '.e(AiFeedbackService::CATEGORIES[$l->category] ?? $l->category).'</small>' : '');
                        }

                        return $lines ? new HtmlString(implode('<br><br>', $lines)) : null;
                    })->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->options(SurveyResponse::STATUSES),
                SelectFilter::make('schedule_id')->label('Kelas')->relationship('schedule', 'code')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('label')->label('Label manual')->icon(Heroicon::OutlinedTag)->color('gray')
                    ->visible(fn (SurveyResponse $record) => AiAccess::canDecide() && $record->comments() !== [])
                    ->modalHeading('Label manual komentar')
                    ->modalDescription('Label manual dipakai untuk mengukur ketepatan klasifikasi AI. Isi sesuai penilaian Anda, bukan menyalin hasil AI.')
                    ->schema(fn (SurveyResponse $record) => [
                        Select::make('question_key')->label('Komentar')->required()->native(false)
                            ->options(collect($record->comments())->map(fn ($t) => mb_strimwidth($t, 0, 90, '...'))->all())
                            ->default(array_key_first($record->comments())),
                        Select::make('sentiment')->label('Sentimen')->options(AiFeedbackService::SENTIMENTS)->required()->native(false),
                        Select::make('category')->label('Kategori')->options(AiFeedbackService::CATEGORIES)->required()->native(false),
                        TextInput::make('theme')->label('Tema (opsional)')->maxLength(120),
                        Toggle::make('actionable')->label('Perlu tindakan'),
                    ])
                    ->action(function (array $data, SurveyResponse $record): void {
                        FeedbackLabel::query()->updateOrCreate(
                            ['response_id' => $record->id, 'question_key' => $data['question_key']],
                            ['sentiment' => $data['sentiment'], 'category' => $data['category'], 'theme' => $data['theme'] ?? null, 'actionable' => (bool) ($data['actionable'] ?? false), 'labeled_by' => auth()->id()],
                        );
                        Notification::make()->title('Label disimpan')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSurveyResponses::route('/')];
    }
}
