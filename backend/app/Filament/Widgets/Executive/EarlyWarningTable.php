<?php

namespace App\Filament\Widgets\Executive;

use App\Filament\Resources\TrainingSchedules\TrainingScheduleResource;
use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\Enrollment;
use App\Services\AiEarlyWarningService;
use App\Services\AiInsightService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\HtmlString;

/**
 * Peringatan dini dibuat AI (model statistik): peserta aktif dengan skor risiko di atas ambang, beserta alasan teratas.
 * Pembanding aturan biasa tetap di tabel "Peserta berisiko tertinggal". Staf bisa memberi umpan balik membantu/tidak.
 */
class EarlyWarningTable extends TableWidget
{
    use ExecutiveWidget;

    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = 21;

    protected int|string|array $columnSpan = 'full';

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $svc = app(AiEarlyWarningService::class);
        if (! $svc->status()['available']) {
            return [];
        }
        $insights = AiInsight::query()->visible()->where('use_case', AiEarlyWarningService::USE_CASE)
            ->where('subject_type', (new Enrollment)->getMorphClass())->where('score', '>=', $svc->threshold())
            ->orderByDesc('id')->get()->unique('subject_id')->sortByDesc('score')->values();
        $enrollments = Enrollment::query()->whereIn('id', $insights->pluck('subject_id'))->with('schedule.package:id,title')->get()->keyBy('id');
        $mine = AiInsightFeedback::query()->where('user_id', auth()->id())->whereIn('ai_insight_id', $insights->pluck('id'))->pluck('helpful', 'ai_insight_id');

        return $insights->map(function (AiInsight $i) use ($enrollments, $mine, $svc) {
            $e = $enrollments->get($i->subject_id);
            $p = $svc->payload($i, $mine->get($i->id));

            return $p + [
                'key' => $i->id,
                'name' => $e?->participant_name ?? '-',
                'class' => $e?->schedule?->code,
                'program' => $e?->schedule?->title ?? $e?->schedule?->package?->title,
            ];
        })->filter(fn ($r) => $r['class'] !== null)->values()->all();
    }

    public function table(Table $table): Table
    {
        $status = app(AiEarlyWarningService::class)->status();

        return $table
            ->heading('Peringatan dini (dibuat AI)')
            ->description($status['available']
                ? 'Skor risiko 0-100 dari model statistik (kehadiran, materi, kuis, tugas, logbook, aktivitas). Hanya untuk staf, trainer, dan perusahaan sponsor; tidak mengubah status peserta.'
                : 'Belum tersedia: '.$status['reason'].'. Pantau peserta lewat tabel "Peserta berisiko tertinggal" di atas.')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $rows = collect($this->rows());

                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage)->keyBy('key')->all(), $rows->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('name')->label('Peserta')->description(fn (array $record) => $record['program']),
                TextColumn::make('class')->label('Kelas')
                    ->url(fn (array $record) => TrainingScheduleResource::getUrl('edit', ['record' => $record['class']])),
                TextColumn::make('score')->label('Skor')->badge()->alignEnd()
                    ->color(fn (array $record) => $record['level'] === 'high' ? 'danger' : 'warning')
                    ->description(fn (array $record) => $record['levelLabel']),
                TextColumn::make('reasons')->label('Alasan utama')->wrap()
                    ->state(fn (array $record) => new HtmlString(collect($record['reasons'])->map(fn ($r) => e($r))->implode('<br>') ?: '-')),
                TextColumn::make('myFeedback')->label('Umpan balik Anda')->placeholder('-')
                    ->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Membantu' : 'Tidak membantu')),
            ])
            ->recordActions([
                Action::make('helpful')->label('Membantu')->icon(Heroicon::OutlinedHandThumbUp)->color('success')->iconButton()
                    ->tooltip('Tandai membantu')
                    ->action(fn (array $record) => $this->feedback($record['insightId'], true)),
                Action::make('not_helpful')->label('Tidak membantu')->icon(Heroicon::OutlinedHandThumbDown)->color('gray')->iconButton()
                    ->tooltip('Tandai tidak membantu')
                    ->action(fn (array $record) => $this->feedback($record['insightId'], false)),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading($status['available'] ? 'Tidak ada peserta di atas ambang risiko' : 'Belum tersedia')
            ->emptyStateDescription($status['available'] ? 'Skor dihitung setiap pagi untuk peserta aktif.' : 'Fitur early warning belum dinyalakan.');
    }

    private function feedback(int $insightId, bool $helpful): void
    {
        $insight = AiInsight::query()->find($insightId);
        if ($insight) {
            app(AiInsightService::class)->feedback($insight, auth()->user(), $helpful);
            Notification::make()->title('Terima kasih, umpan balik dicatat')->success()->send();
        }
    }
}
