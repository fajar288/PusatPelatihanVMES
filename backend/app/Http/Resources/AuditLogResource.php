<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->actor_name,
            'subject' => $this->auditable_type ? class_basename($this->auditable_type) : null,
            'subjectId' => $this->auditable_id,
            'before' => $this->before,
            'after' => $this->after,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
