<?php

namespace App\Http\Resources;

use App\Models\Registration;
use App\Support\IdDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Registration */
class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->schedule;

        return [
            'number' => $this->registration_number,
            'type' => $this->type,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'nextStatuses' => array_map(fn ($st) => $st->value, $this->status->next()),
            'cancellable' => $this->status->cancellableByOwner(),
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'position' => $this->position,
            'participantsCount' => $this->participants_count,
            'notes' => $this->notes,
            'priceQuoted' => $this->price_quoted !== null ? (float) $this->price_quoted : null,
            'discount' => (float) $this->discount,
            'total' => $this->total !== null ? (float) $this->total : null,
            'statusNote' => $this->status_note,
            'createdAt' => $this->created_at?->toIso8601String(),
            'createdLabel' => IdDate::long($this->created_at),
            'confirmedAt' => $this->confirmed_at?->toIso8601String(),
            'schedule' => $s ? [
                'code' => $s->code,
                'title' => $s->title ?? $s->package?->title,
                'packageSlug' => $s->package?->slug,
                'startDate' => $s->start_date->toDateString(),
                'endDate' => $s->end_date->toDateString(),
                'dateLabel' => IdDate::long($s->start_date),
                'city' => $s->city,
                'mode' => $s->mode,
                'time' => $s->timeLabel(),
            ] : null,
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($p) => ['name' => $p->name, 'email' => $p->email, 'phone' => $p->phone, 'position' => $p->position])),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
