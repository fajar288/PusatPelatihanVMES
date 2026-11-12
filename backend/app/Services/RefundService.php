<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refund manual (PRD Roadmap 5 §5.1: reason, approver, timestamp, reference). Alur: diajukan (alasan) → disetujui orang
 * lain yang berizin `payments.refund.approve` → finance mencatat transfer balik (referensi + tanggal). Dana dikembalikan di
 * luar sistem. Refund TIDAK otomatis membatalkan pendaftaran/enrollment — itu keputusan terpisah.
 */
class RefundService
{
    public function request(Invoice $invoice, User $actor, float $amount, string $reason, ?PaymentTransaction $tx = null): PaymentRefund
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Tuliskan alasan refund (minimal 10 karakter).']);
        }

        return DB::transaction(function () use ($invoice, $actor, $amount, $reason, $tx) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $amount = round($amount, 2);
            $available = $this->refundable($invoice);
            if ($amount <= 0 || $amount > $available) {
                throw ValidationException::withMessages(['amount' => 'Nominal refund harus antara Rp 1 dan '.PaymentService::rupiah($available).' (dana terbayar yang belum/sedang direfund).']);
            }
            if ($tx && ($tx->invoice_id !== $invoice->id || $tx->status !== 'paid')) {
                throw ValidationException::withMessages(['payment_transaction_id' => 'Transaksi tidak sesuai dengan invoice ini.']);
            }
            $refund = PaymentRefund::query()->create([
                'code' => PaymentRefund::nextCode(), 'invoice_id' => $invoice->id, 'payment_transaction_id' => $tx?->id,
                'amount' => $amount, 'reason' => $reason, 'status' => 'requested', 'requested_by' => $actor->id, 'requested_at' => now(),
            ]);
            AuditLog::record('refund.requested', $refund, null, ['invoice' => $invoice->invoice_number, 'amount' => $amount, 'reason' => $reason], $actor);

            return $refund;
        });
    }

    public function approve(PaymentRefund $refund, User $actor, ?string $note = null): PaymentRefund
    {
        $this->assertApprover($refund, $actor);

        return $this->decide($refund, $actor, 'approved', $note);
    }

    public function reject(PaymentRefund $refund, User $actor, string $note): PaymentRefund
    {
        $this->assertApprover($refund, $actor);
        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => 'Tuliskan alasan penolakan.']);
        }

        return $this->decide($refund, $actor, 'rejected', $note);
    }

    /** Finance mencatat transfer balik yang sudah dilakukan di luar sistem. */
    public function complete(PaymentRefund $refund, User $actor, string $reference, string $transferredOn): PaymentRefund
    {
        if (trim($reference) === '') {
            throw ValidationException::withMessages(['transfer_reference' => 'Referensi transfer balik wajib diisi.']);
        }

        return DB::transaction(function () use ($refund, $actor, $reference, $transferredOn) {
            $refund = PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status === 'completed') {
                return $refund;
            }
            if ($refund->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Refund harus disetujui sebelum dicatat selesai.']);
            }
            $date = Carbon::parse($transferredOn)->toDateString();
            $refund->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now(), 'transfer_reference' => trim($reference), 'transferred_on' => $date]);
            AuditLog::record('refund.completed', $refund, ['status' => 'approved'], ['status' => 'completed', 'reference' => trim($reference), 'transferred_on' => $date], $actor);

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($refund->invoice_id);
            $before = ['status' => $invoice->status, 'amount_refunded' => (float) $invoice->amount_refunded];
            $refunded = round((float) $invoice->amount_refunded + (float) $refund->amount, 2);
            $invoice->update(['amount_refunded' => $refunded, 'status' => $refunded >= (float) $invoice->amount_paid ? 'refunded' : $invoice->status]);
            AuditLog::record('invoice.refunded', $invoice, $before, ['status' => $invoice->status, 'amount_refunded' => $refunded, 'refund' => $refund->code], $actor);

            return $refund;
        });
    }

    /** Dana terbayar yang masih bisa direfund (dikurangi refund selesai dan yang sedang berjalan). */
    public function refundable(Invoice $invoice): float
    {
        $inFlight = (float) $invoice->refunds()->whereIn('status', ['requested', 'approved'])->sum('amount');

        return round(max(0, (float) $invoice->amount_paid - (float) $invoice->amount_refunded - $inFlight), 2);
    }

    private function decide(PaymentRefund $refund, User $actor, string $to, ?string $note): PaymentRefund
    {
        return DB::transaction(function () use ($refund, $actor, $to, $note) {
            $refund = PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status !== 'requested') {
                throw ValidationException::withMessages(['status' => "Refund {$refund->code} sudah diputuskan ({$refund->statusLabel()})."]);
            }
            $refund->update(['status' => $to, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            AuditLog::record('refund.'.$to, $refund, ['status' => 'requested'], ['status' => $to, 'note' => $note], $actor);

            return $refund;
        });
    }

    /** Penyetuju harus berizin dan bukan orang yang mengajukan (pemisahan tugas). */
    private function assertApprover(PaymentRefund $refund, User $actor): void
    {
        if (! $actor->hasPermission('payments.refund.approve') && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['approver' => 'Anda tidak berwenang menyetujui refund.']);
        }
        if ($refund->requested_by === $actor->id) {
            throw ValidationException::withMessages(['approver' => 'Refund harus diputuskan oleh orang lain selain pengaju.']);
        }
    }
}
