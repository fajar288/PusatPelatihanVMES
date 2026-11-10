<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\IdDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'invoice' => $this->invoice_number,
            'registration' => $this->whenLoaded('registration', fn () => $this->registration->registration_number),
            'amount' => (float) $this->amount,
            'dueDate' => $this->due_date?->toDateString(),
            'dueLabel' => IdDate::long($this->due_date),
            'method' => $this->method,
            'status' => $this->status,
            'proofUploaded' => $this->proof_path !== null,
            'proofUploadedAt' => $this->proof_uploaded_at?->toIso8601String(),
            'verifiedAt' => $this->verified_at?->toIso8601String(),
            'note' => $this->note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
