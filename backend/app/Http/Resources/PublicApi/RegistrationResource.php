<?php

namespace App\Http\Resources\PublicApi;

use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API Publik v1 — pendaftaran yang dibuat klien API. @mixin Registration */
class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $invoice = $this->relationLoaded('invoices') ? $this->invoices->where('status', '!=', 'cancelled')->first() : null;
        $schedule = $this->schedule;

        return [
            'number' => $this->registration_number,
            'external_ref' => $this->external_ref,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_note' => $this->status_note,
            'type' => $this->type,
            'schedule' => $schedule ? [
                'code' => $schedule->code,
                'title' => $schedule->title ?? $schedule->package?->title,
                'start_date' => $schedule->start_date->toDateString(),
                'end_date' => $schedule->end_date->toDateString(),
            ] : null,
            'contact' => ['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'company' => $this->company, 'position' => $this->position],
            'participants_count' => $this->participants_count,
            'participants' => $this->relationLoaded('participants')
                ? $this->participants->map(fn ($p) => ['name' => $p->name, 'email' => $p->email, 'phone' => $p->phone, 'position' => $p->position])->values()->all()
                : [],
            'price' => $this->price_quoted !== null ? (float) $this->price_quoted : null,
            'discount' => (float) $this->discount,
            'total' => $this->total !== null ? (float) $this->total : null,
            'currency' => 'IDR',
            'invoice' => $invoice ? [
                'number' => $invoice->invoice_number,
                'amount' => (float) $invoice->total,
                'amount_paid' => (float) $invoice->amount_paid,
                'status' => $invoice->status,
                'due_date' => $invoice->due_date?->toDateString(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
        ];
    }
}
