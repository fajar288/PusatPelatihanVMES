<?php

namespace App\Filament\Resources\TrainingLogbooks\Pages;

use App\Filament\Resources\TrainingLogbooks\TrainingLogbookResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTrainingLogbook extends CreateRecord
{
    protected static string $resource = TrainingLogbookResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data + ['created_by' => auth()->id(), 'status' => 'draft', 'is_locked' => false]);
        AuditLog::record('logbook.created', $record, null, ['topic' => $record->topic, 'date' => $record->date?->toDateString(), 'by' => 'admin']);

        return $record;
    }
}
