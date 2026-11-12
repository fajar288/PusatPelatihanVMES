<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Support\IdDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invoice untuk pembayar (portal member & portal perusahaan). Tidak memuat catatan internal finance selain alasan
 * penolakan bukti dan catatan selisih yang memang perlu diketahui pembayar.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $txs = $this->relationLoaded('transactions') ? $this->transactions : collect();
        $overdue = $this->isPayable() && $this->due_date?->isBefore(today()) && (float) $this->amount_paid <= 0;
        $awaiting = $txs->contains('status', 'pending');

        return [
            'number' => $this->invoice_number,
            'registration' => $this->registration?->registration_number,
            'trainingRequest' => $this->trainingRequest?->code,
            'source' => $this->sourceLabel(),
            'status' => $this->status,
            'statusLabel' => $awaiting && $this->isPayable() ? 'Menunggu verifikasi' : $this->statusLabel(),
            'awaitingVerification' => $awaiting,
            'payable' => $this->isPayable() && ! $overdue,
            'overdue' => $overdue,
            'billTo' => [
                'name' => $this->bill_to_name, 'email' => $this->bill_to_email, 'company' => $this->bill_to_company,
                'address' => $this->bill_to_address, 'taxId' => $this->bill_to_tax_id,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'description' => $i->description, 'quantity' => (float) $i->quantity, 'unitPrice' => (float) $i->unit_price, 'amount' => (float) $i->amount,
            ])->values()),
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'discountNote' => $this->discount_note,
            'taxRate' => (float) $this->tax_rate,
            'taxLabel' => $this->tax_label,
            'taxAmount' => (float) $this->tax_amount,
            'showTax' => (float) $this->tax_rate > 0 || (float) $this->tax_amount > 0,
            'total' => (float) $this->total,
            'amountPaid' => (float) $this->amount_paid,
            'amountRefunded' => (float) $this->amount_refunded,
            'outstanding' => $this->outstanding(),
            'installmentNo' => $this->installment_no,
            'installmentTotal' => $this->installment_total,
            'description' => $this->description,
            'notes' => $this->notes,
            'settlementNote' => $this->settlement_note,
            'issuedAt' => $this->issued_at?->toIso8601String(),
            'issuedLabel' => IdDate::long($this->issued_at ?? $this->created_at),
            'dueDate' => $this->due_date?->toDateString(),
            'dueLabel' => IdDate::long($this->due_date),
            'paidLabel' => IdDate::long($this->paid_at),
            'transactions' => $this->whenLoaded('transactions', fn () => $txs->where('status', '!=', 'cancelled')->map(fn (PaymentTransaction $t) => [
                'code' => $t->code,
                'method' => $t->methodLabel(),
                'destination' => $t->destination,
                'amount' => (float) $t->amount,
                'amountReceived' => $t->amount_received !== null ? (float) $t->amount_received : null,
                'transferLabel' => IdDate::long($t->transfer_date),
                'status' => $t->status,
                'statusLabel' => $t->statusLabel(),
                'submittedAt' => $t->submitted_at?->toIso8601String(),
                'submittedLabel' => IdDate::long($t->submitted_at),
                'verifiedLabel' => IdDate::long($t->verified_at),
                'reviewNote' => $t->status === 'failed' ? $t->review_note : null,
                'proofUploaded' => $t->proof_path !== null,
            ])->values()),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->whereIn('status', ['requested', 'approved', 'completed'])->map(fn (PaymentRefund $r) => [
                'code' => $r->code, 'amount' => (float) $r->amount, 'status' => $r->status, 'statusLabel' => $r->statusLabel(),
                'completedLabel' => IdDate::long($r->transferred_on), 'reference' => $r->status === 'completed' ? $r->transfer_reference : null,
            ])->values()),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
