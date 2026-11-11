<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $fields = ['title', 'body', 'mail_subject', 'mail_body', 'channels', 'is_active'];
        $before = $record->only($fields);
        $record->update($data);
        AuditLog::record('notification_template.updated', $record, $before, $record->only($fields));

        return $record;
    }
}
