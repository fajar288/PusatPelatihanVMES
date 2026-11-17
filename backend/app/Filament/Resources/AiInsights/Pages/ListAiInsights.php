<?php

namespace App\Filament\Resources\AiInsights\Pages;

use App\Filament\Resources\AiInsights\AiInsightResource;
use App\Filament\Support\AiAccess;
use App\Models\AiInsight;
use App\Models\CompetencyTarget;
use App\Models\Organization;
use App\Services\AiCorporateInsightService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListAiInsights extends ListRecords
{
    protected static string $resource = AiInsightResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Draf insight kompetensi untuk satu organisasi (selain jadwal mingguan). Tetap harus disetujui sebelum tampil.
            Action::make('corporateInsight')->label('Buat insight perusahaan')->icon(Heroicon::OutlinedBuildingOffice2)->color('gray')
                ->visible(fn () => AiAccess::canDecide() && app(AiCorporateInsightService::class)->status()['available'])
                ->modalHeading('Buat draf insight perusahaan')
                ->modalDescription('AI hanya menerima angka gabungan gap kompetensi dan hasil training bersponsor, tanpa nama karyawan. Hasilnya masuk antrean tinjauan dan baru tampil di portal perusahaan setelah disetujui.')
                ->schema([
                    Select::make('organization_id')->label('Organisasi')->required()->searchable()
                        ->options(fn () => Organization::query()->whereIn('id', CompetencyTarget::query()->distinct()->select('organization_id'))
                            ->active()->orderBy('legal_name')->get(['id', 'code', 'legal_name'])
                            ->mapWithKeys(fn (Organization $o) => [$o->id => $o->code.' · '.$o->legal_name])),
                    Toggle::make('force')->label('Buat ulang walau data tidak berubah'),
                ])
                ->action(function (array $data): void {
                    $insight = app(AiCorporateInsightService::class)->generate(Organization::query()->findOrFail($data['organization_id']), (bool) ($data['force'] ?? false));
                    $insight
                        ? Notification::make()->title('Draf insight dibuat')->body('Tinjau di tab Antrean tinjauan.')->success()->send()
                        : Notification::make()->title('Tidak ada draf baru')->body('Data kurang (minimal 3 karyawan bertarget), tidak berubah sejak insight terakhir, atau model gagal. Laporan gap tetap tersedia.')->warning()->send();
                }),
        ];
    }

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
