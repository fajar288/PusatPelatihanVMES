<?php

namespace App\Filament\Resources\TrainingSchedules\Pages;

use App\Filament\Resources\TrainingSchedules\TrainingScheduleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainingSchedule extends EditRecord
{
    protected static string $resource = TrainingScheduleResource::class;

    /** Alasan perubahan dari form → properti transien model → dicatat TrainingScheduleObserver. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->record->changeReason = $data['change_reason'] ?? null;
        unset($data['change_reason']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
