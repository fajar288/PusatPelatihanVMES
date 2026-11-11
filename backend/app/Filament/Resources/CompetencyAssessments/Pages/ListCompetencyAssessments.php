<?php

namespace App\Filament\Resources\CompetencyAssessments\Pages;

use App\Filament\Resources\CompetencyAssessments\CompetencyAssessmentResource;
use App\Models\Enrollment;
use App\Services\MonitoringService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;

class ListCompetencyAssessments extends ListRecords
{
    protected static string $resource = CompetencyAssessmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('buat')->label('Buat penilaian')->icon('heroicon-o-plus')
                ->schema([
                    Select::make('enrollment_id')->label('Peserta (kelas)')->required()->searchable()
                        ->options(fn () => Enrollment::query()->with('schedule')->doesntHave('assessment')->latest('id')->limit(300)->get()
                            ->mapWithKeys(fn (Enrollment $e) => [$e->id => $e->participant_name.' - '.($e->schedule?->code ?? '-')])),
                ])
                ->action(function (array $data, MonitoringService $svc) {
                    $a = $svc->assessmentFor(Enrollment::query()->with('schedule.package')->findOrFail($data['enrollment_id']));

                    return redirect(CompetencyAssessmentResource::getUrl('edit', ['record' => $a]));
                }),
        ];
    }
}
