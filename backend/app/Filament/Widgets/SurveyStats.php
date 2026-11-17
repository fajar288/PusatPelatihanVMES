<?php

namespace App\Filament\Widgets;

use App\Models\FeedbackLabel;
use App\Models\SurveyResponse;
use App\Services\AiFeedbackService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Ringkasan survei evaluasi: tingkat respons, rata-rata skor, komentar terklasifikasi AI, dan label manual. */
class SurveyStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?bool $isLazyLoaded = false;

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $all = SurveyResponse::query()->count();
        $submitted = SurveyResponse::query()->where('status', 'submitted')->with('template')->get();
        $avg = $submitted->map->averageScore()->filter()->avg();
        $comments = (int) $submitted->sum(fn (SurveyResponse $r) => count($r->comments()));
        $classified = app(AiFeedbackService::class)->classifications()->count();
        $labeled = FeedbackLabel::query()->count();
        $sentiment = app(AiFeedbackService::class)->classifications()->countBy('sentiment');

        return [
            Stat::make('Survei terisi', $submitted->count().' / '.$all)
                ->description($all > 0 ? 'Tingkat respons '.round($submitted->count() / $all * 100).'%' : 'Belum ada undangan')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck),
            Stat::make('Rata-rata skor', $avg === null ? '-' : number_format((float) $avg, 2, ',', '.').' / 5')
                ->description('Dari semua pertanyaan skala')
                ->icon(Heroicon::OutlinedStar),
            Stat::make('Komentar', (string) $comments)
                ->description($classified.' diklasifikasi AI, '.$labeled.' diberi label manual')
                ->icon(Heroicon::OutlinedChatBubbleBottomCenterText),
            Stat::make('Sentimen (dibuat AI)', $classified > 0 ? ($sentiment['negatif'] ?? 0).' negatif' : '-')
                ->description($classified > 0 ? ($sentiment['positif'] ?? 0).' positif, '.($sentiment['netral'] ?? 0).' netral' : 'Analisis AI belum berjalan')
                ->icon(Heroicon::OutlinedFaceSmile),
        ];
    }
}
