<?php

namespace App\Filament\Resources\AiInsights\Pages;

use App\Filament\Resources\AiInsights\AiInsightResource;
use App\Models\AiInsight;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAiInsights extends ListRecords
{
    protected static string $resource = AiInsightResource::class;

    public function getTabs(): array
    {
        $tab = fn (string $label, ?string $status) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $status ? $query->where('status', $status) : $query);

        return [
            'review' => $tab('Antrean tinjauan', AiInsight::STATUS_DRAFT)
                ->badge(fn () => AiInsight::query()->pendingReview()->count() ?: null)->badgeColor('warning'),
            'approved' => $tab('Disetujui', AiInsight::STATUS_APPROVED),
            'rejected' => $tab('Ditolak', AiInsight::STATUS_REJECTED),
            'expired' => $tab('Kedaluwarsa', AiInsight::STATUS_EXPIRED),
            'all' => $tab('Semua', null),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'review';
    }
}
