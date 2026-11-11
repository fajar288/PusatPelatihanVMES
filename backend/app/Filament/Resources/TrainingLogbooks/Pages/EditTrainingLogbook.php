<?php

namespace App\Filament\Resources\TrainingLogbooks\Pages;

use App\Filament\Resources\TrainingLogbooks\TrainingLogbookResource;
use App\Models\AuditLog;
use App\Models\TrainingLogbook;
use App\Services\MonitoringService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** Edit logbook: validasi/kunci, buka kunci dengan alasan; perubahan pada logbook terkunci dicatat sebagai pasca-validasi (PRD §9.1). */
class EditTrainingLogbook extends EditRecord
{
    protected static string $resource = TrainingLogbookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('validasi')->label('Validasi & kunci')->icon('heroicon-o-lock-closed')->color('success')->requiresConfirmation()
                ->visible(fn (TrainingLogbook $record) => ! $record->isValidated())
                ->action(function (TrainingLogbook $record, MonitoringService $svc): void {
                    $svc->validateLogbook($record, auth()->user());
                    Notification::make()->title('Logbook divalidasi dan dikunci')->success()->send();
                    $this->refreshFormData(['status', 'is_locked']);
                }),
            Action::make('bukaKunci')->label('Buka kunci')->icon('heroicon-o-lock-open')->color('warning')
                ->visible(fn (TrainingLogbook $record) => $record->is_locked)
                ->schema([Textarea::make('reason')->label('Alasan membuka kunci')->required()->rows(2)])
                ->action(function (array $data, TrainingLogbook $record, MonitoringService $svc): void {
                    $svc->unlockLogbook($record, auth()->user(), $data['reason']);
                    Notification::make()->title('Kunci dibuka dan tercatat di audit log')->warning()->send();
                    $this->refreshFormData(['status', 'is_locked']);
                }),
            DeleteAction::make()->visible(fn (TrainingLogbook $record) => ! $record->is_locked),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TrainingLogbook $record */
        $before = array_intersect_key($record->getOriginal(), $data);
        $record->update($data);
        $changes = $record->getChanges();
        unset($changes['updated_at']);
        if ($changes !== []) {
            AuditLog::record($record->is_locked ? 'logbook.updated_after_validation' : 'logbook.updated', $record, array_intersect_key($before, $changes), $changes);
        }

        return $record;
    }
}
