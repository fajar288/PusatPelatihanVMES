<?php

namespace App\Filament\Resources\CompetencyAssessments\Pages;

use App\Filament\Resources\CompetencyAssessments\CompetencyAssessmentResource;
use App\Models\AuditLog;
use App\Models\CompetencyAssessment;
use App\Services\MonitoringService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Edit penilaian: skor per kriteria (baris dibuat untuk semua kriteria rubrik), validasi/kunci, buka kunci; audit perubahan. */
class EditCompetencyAssessment extends EditRecord
{
    protected static string $resource = CompetencyAssessmentResource::class;

    protected function beforeFill(): void
    {
        /** @var CompetencyAssessment $a */
        $a = $this->record->load(['rubric.criteria', 'scores', 'enrollment.schedule.package']);
        foreach ($a->rubric->criteria as $c) {
            $a->scores()->firstOrCreate(['criterion_id' => $c->id]);
        }
        $a->unsetRelation('scores');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('validasi')->label('Validasi & kunci')->icon('heroicon-o-lock-closed')->color('success')->requiresConfirmation()
                ->visible(fn (CompetencyAssessment $record) => ! $record->isValidated())
                ->action(function (CompetencyAssessment $record, MonitoringService $svc): void {
                    try {
                        $svc->validateAssessment($record, auth()->user());
                        Notification::make()->title('Penilaian divalidasi dan dikunci')->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->refreshFormData(['status', 'is_locked', 'total_percent', 'is_competent']);
                }),
            Action::make('bukaKunci')->label('Buka kunci')->icon('heroicon-o-lock-open')->color('warning')
                ->visible(fn (CompetencyAssessment $record) => $record->is_locked)
                ->schema([Textarea::make('reason')->label('Alasan membuka kunci')->required()->rows(2)])
                ->action(function (array $data, CompetencyAssessment $record, MonitoringService $svc): void {
                    $svc->unlockAssessment($record, auth()->user(), $data['reason']);
                    Notification::make()->title('Kunci dibuka dan tercatat di audit log')->warning()->send();
                    $this->refreshFormData(['status', 'is_locked']);
                }),
            DeleteAction::make()->visible(fn (CompetencyAssessment $record) => ! $record->is_locked),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CompetencyAssessment $record */
        $before = ['summary' => $record->summary, 'recommendation' => $record->recommendation, 'total_percent' => $record->total_percent, 'scores' => $record->scores()->pluck('score', 'criterion_id')->all()];
        $record->update($data + ['assessed_by' => auth()->id(), 'assessed_at' => now()]);

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var CompetencyAssessment $a */
        $a = $this->record;
        $a->recalculate();
        $a->save();
        AuditLog::record($a->is_locked ? 'assessment.updated_after_validation' : 'assessment.saved', $a, null, ['summary' => $a->summary, 'total_percent' => $a->total_percent, 'is_competent' => $a->is_competent, 'scores' => $a->scores()->pluck('score', 'criterion_id')->all(), 'by' => 'admin']);
    }
}
