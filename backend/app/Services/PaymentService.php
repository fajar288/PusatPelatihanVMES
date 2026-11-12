<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\PaymentTransaction;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\PaymentTransactionRejected;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Pembayaran MANUAL (PRD Roadmap 5 §5.1 diterapkan ke alur manual, keputusan 2026-11-12):
 * - setiap bukti transfer = transaksi `pending`; status invoice hanya berubah lewat verifikasi finance, bukan klaim pembayar;
 * - invoice lunas/dibatalkan/dikembalikan/kedaluwarsa tidak menerima bukti baru;
 * - verifikasi idempoten: memverifikasi ulang transaksi yang sudah `paid` tidak membuat enrollment/notifikasi ganda;
 * - selisih nominal dicatat (kurang bayar tetap terbuka kecuali finance menyatakan lunas dengan catatan).
 */
class PaymentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly RegistrationService $registrations,
        private readonly TrainingRequestService $requests,
    ) {}

    /**
     * Pembayar (member / finance perusahaan) mengunggah bukti transfer.
     *
     * @param  array{amount?: float|string|null, transfer_date?: string|null, payment_account_id?: int|string|null, payer_name?: string|null, note?: string|null}  $data
     */
    public function submitProof(Invoice $invoice, User $actor, UploadedFile $proof, array $data = [], string $source = 'member'): PaymentTransaction
    {
        $this->assertAcceptsProof($invoice->refresh());
        $path = $proof->store('payments/'.$invoice->invoice_number, config('filesystems.default'));

        try {
            $tx = $this->createPendingTransaction($invoice, $actor, $path, $data, $source);
        } catch (\Throwable $e) {
            Storage::disk(config('filesystems.default'))->delete($path);
            throw $e;
        }

        return $tx->refresh();
    }

    /** @param  array<string, mixed>  $data */
    private function createPendingTransaction(Invoice $invoice, User $actor, string $path, array $data, string $source): PaymentTransaction
    {
        return DB::transaction(function () use ($invoice, $actor, $path, $data, $source) {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertAcceptsProof($invoice);

            // satu bukti aktif per invoice: bukti lama yang belum diverifikasi diganti (tetap tersimpan sebagai riwayat)
            $invoice->transactions()->where('status', 'pending')->get()->each(function (PaymentTransaction $old) use ($actor): void {
                $old->update(['status' => 'cancelled', 'review_note' => 'Diganti bukti baru oleh pembayar']);
                AuditLog::record('payment.transaction_replaced', $old, ['status' => 'pending'], ['status' => 'cancelled'], $actor);
            });

            $account = ! empty($data['payment_account_id']) ? PaymentAccount::query()->where('is_active', true)->find($data['payment_account_id']) : null;
            $amount = isset($data['amount']) && $data['amount'] !== '' && $data['amount'] !== null ? round((float) $data['amount'], 2) : $invoice->outstanding();
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal transfer harus lebih dari 0.']);
            }
            $tx = PaymentTransaction::query()->create([
                'code' => PaymentTransaction::nextCode(),
                'invoice_id' => $invoice->id,
                'method' => $account?->type ?? 'bank_transfer',
                'payment_account_id' => $account?->id,
                'destination' => $account?->summary(),
                'payer_name' => ($data['payer_name'] ?? null) ?: $actor->name,
                'amount' => $amount,
                'transfer_date' => ($data['transfer_date'] ?? null) ?: today()->toDateString(),
                'proof_path' => $path,
                'status' => 'pending',
                'source' => $source,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'note' => ($data['note'] ?? null) ?: null,
            ]);
            AuditLog::record('payment.proof_submitted', $tx, null, ['invoice' => $invoice->invoice_number, 'amount' => $amount, 'path' => $path, 'source' => $source], $actor);

            $reg = $invoice->registration;
            if ($reg && $reg->status === RegistrationStatus::WaitingPayment) {
                $this->registrations->transition($reg, RegistrationStatus::PaymentVerification, $actor, 'Bukti pembayaran diunggah');
            }

            return $tx;
        });
    }

    /**
     * Finance mencatat pembayaran yang diterima tanpa unggahan pembayar (mis. transfer langsung / tunai), lalu langsung
     * memverifikasinya.
     *
     * @param  array{amount: float|string, transfer_date?: string|null, payment_account_id?: int|string|null, method?: string|null, payer_name?: string|null, note?: string|null, settle?: bool}  $data
     */
    public function recordReceived(Invoice $invoice, User $actor, array $data, ?UploadedFile $proof = null): PaymentTransaction
    {
        $path = $proof?->store('payments/'.$invoice->invoice_number, config('filesystems.default'));
        $tx = DB::transaction(function () use ($invoice, $actor, $data, $path) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, ['pending', 'failed', 'expired'], true)) {
                throw ValidationException::withMessages(['invoice' => "Invoice berstatus \"{$invoice->statusLabel()}\" — tidak bisa menerima pembayaran baru."]);
            }
            if ($invoice->hasPendingTransaction()) {
                throw ValidationException::withMessages(['invoice' => 'Ada bukti pembayaran yang menunggu verifikasi. Verifikasi atau tolak bukti itu dulu.']);
            }
            $account = ! empty($data['payment_account_id']) ? PaymentAccount::query()->find($data['payment_account_id']) : null;
            $tx = PaymentTransaction::query()->create([
                'code' => PaymentTransaction::nextCode(),
                'invoice_id' => $invoice->id,
                'method' => ($data['method'] ?? null) ?: ($account?->type ?? 'bank_transfer'),
                'payment_account_id' => $account?->id,
                'destination' => $account?->summary(),
                'payer_name' => ($data['payer_name'] ?? null) ?: $invoice->bill_to_name,
                'amount' => round((float) $data['amount'], 2),
                'transfer_date' => ($data['transfer_date'] ?? null) ?: today()->toDateString(),
                'proof_path' => $path,
                'status' => 'pending',
                'source' => 'admin',
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'note' => ($data['note'] ?? null) ?: null,
            ]);
            AuditLog::record('payment.recorded_by_finance', $tx, null, ['invoice' => $invoice->invoice_number, 'amount' => (float) $tx->amount], $actor);

            return $tx;
        });

        return $this->verify($tx, $actor, (float) $data['amount'], null, $data['note'] ?? null, (bool) ($data['settle'] ?? false));
    }

    /**
     * Verifikasi finance. `$received` = nominal yang benar-benar masuk (bawaan: nominal yang dinyatakan).
     * Kurang bayar → invoice tetap terbuka, kecuali `$settle` (finance menyatakan lunas, selisih dicatat).
     */
    public function verify(PaymentTransaction $tx, User $actor, ?float $received = null, ?string $transferDate = null, ?string $note = null, bool $settle = false): PaymentTransaction
    {
        $tx = DB::transaction(function () use ($tx, $actor, $received, $transferDate, $note, $settle) {
            /** @var PaymentTransaction $tx */
            $tx = PaymentTransaction::query()->lockForUpdate()->findOrFail($tx->id);
            if ($tx->status === 'paid') {
                return $tx;   // idempoten: verifikasi ganda tidak memicu efek apa pun
            }
            if ($tx->status !== 'pending') {
                throw ValidationException::withMessages(['status' => "Transaksi {$tx->code} berstatus \"{$tx->statusLabel()}\" — tidak bisa diverifikasi."]);
            }
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($tx->invoice_id);
            if (! in_array($invoice->status, ['pending', 'failed', 'expired'], true)) {
                throw ValidationException::withMessages(['invoice' => "Invoice {$invoice->invoice_number} berstatus \"{$invoice->statusLabel()}\". Tolak transaksi ini; bila dana sudah masuk, catat sebagai refund."]);
            }
            $reg = $invoice->registration;
            if ($reg && $reg->type === Registration::TYPE_CORPORATE && ! $reg->organization_id) {
                throw ValidationException::withMessages(['organization_id' => "Pendaftaran kolektif {$reg->registration_number} belum ditautkan ke organisasi. Buka pendaftaran → Tautkan organisasi, lalu verifikasi lagi."]);
            }

            $received = round($received ?? (float) $tx->amount, 2);
            if ($received <= 0) {
                throw ValidationException::withMessages(['amount_received' => 'Nominal diterima harus lebih dari 0.']);
            }
            $outstanding = $invoice->outstanding();
            $difference = round($received - $outstanding, 2);
            $tx->update([
                'status' => 'paid', 'amount_received' => $received, 'difference' => $difference,
                'transfer_date' => $transferDate ?: $tx->transfer_date, 'verified_by' => $actor->id, 'verified_at' => now(), 'review_note' => $note,
            ]);

            $before = ['status' => $invoice->status, 'amount_paid' => (float) $invoice->amount_paid];
            $paid = $difference >= 0 || $settle;
            $settlement = match (true) {
                $difference > 0 => 'Lebih bayar '.self::rupiah($difference).' (transaksi '.$tx->code.').',
                $difference < 0 && $settle => 'Kurang bayar '.self::rupiah(-$difference).' dinyatakan lunas oleh finance'.($note ? ': '.$note : '.'),
                default => null,
            };
            $invoice->update([
                'amount_paid' => round((float) $invoice->amount_paid + $received, 2),
                'status' => $paid ? 'paid' : 'pending',
                'paid_at' => $paid ? now() : null,
                'expired_at' => null,
                'settlement_note' => $settlement ? trim(($invoice->settlement_note ? $invoice->settlement_note."\n" : '').$settlement) : $invoice->settlement_note,
            ]);
            AuditLog::record('payment.verified', $tx, ['status' => 'pending'], ['status' => 'paid', 'received' => $received, 'difference' => $difference, 'settled' => $settle], $actor);
            AuditLog::record($paid ? 'invoice.paid' : 'invoice.partially_paid', $invoice, $before, ['status' => $invoice->status, 'amount_paid' => (float) $invoice->amount_paid, 'transaction' => $tx->code], $actor);

            if ($paid) {
                $this->onInvoicePaid($invoice, $tx, $actor);
            } elseif ($reg && $reg->status === RegistrationStatus::PaymentVerification) {
                $this->registrations->transition($reg, RegistrationStatus::WaitingPayment, $actor, 'Pembayaran diterima sebagian — kurang '.self::rupiah(-$difference).'. Silakan lunasi sisanya.');
            }

            return $tx;
        });

        return $tx->refresh();
    }

    /** Tolak bukti (mis. dana tidak masuk / bukti tidak terbaca). Invoice kembali bisa dibayar. */
    public function reject(PaymentTransaction $tx, User $actor, string $reason): PaymentTransaction
    {
        $notifyDirectly = false;
        $tx = DB::transaction(function () use ($tx, $actor, $reason, &$notifyDirectly) {
            $tx = PaymentTransaction::query()->lockForUpdate()->findOrFail($tx->id);
            if ($tx->status === 'failed') {
                return $tx;
            }
            if ($tx->status !== 'pending') {
                throw ValidationException::withMessages(['status' => "Transaksi {$tx->code} berstatus \"{$tx->statusLabel()}\" — tidak bisa ditolak."]);
            }
            $tx->update(['status' => 'failed', 'review_note' => $reason, 'verified_by' => $actor->id, 'verified_at' => now()]);
            AuditLog::record('payment.rejected', $tx, ['status' => 'pending'], ['status' => 'failed', 'reason' => $reason], $actor);

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($tx->invoice_id);
            if ($invoice->status === 'pending' && (float) $invoice->amount_paid <= 0) {
                $invoice->update(['status' => 'failed']);
                AuditLog::record('invoice.failed', $invoice, ['status' => 'pending'], ['status' => 'failed', 'transaction' => $tx->code], $actor);
            }
            $reg = $invoice->registration;
            if ($reg && $reg->status === RegistrationStatus::PaymentVerification) {
                // email perubahan status pendaftaran sudah memuat alasan → tidak perlu email kedua
                $this->registrations->transition($reg, RegistrationStatus::WaitingPayment, $actor, 'Bukti pembayaran ditolak: '.$reason);
            } else {
                $notifyDirectly = true;
            }

            return $tx;
        });

        if ($notifyDirectly && $tx->submitter && $tx->source !== 'admin') {
            $tx->submitter->notify(new PaymentTransactionRejected($tx->load('invoice')));
        }

        return $tx;
    }

    public function assertAcceptsProof(Invoice $invoice): void
    {
        if ($this->invoices->isOverdue($invoice) && ! $invoice->hasPendingTransaction()) {
            $this->invoices->expire($invoice);
        }
        if (! $invoice->isPayable()) {
            $msg = match ($invoice->status) {
                'paid' => 'Invoice ini sudah lunas — tidak menerima bukti pembayaran baru.',
                'expired' => 'Invoice ini sudah lewat jatuh tempo. Hubungi tim VMES untuk memperpanjang tagihan.',
                default => "Invoice berstatus \"{$invoice->statusLabel()}\" — tidak menerima bukti pembayaran.",
            };
            throw ValidationException::withMessages(['invoice' => $msg]);
        }
        $reg = $invoice->registration;
        if ($reg && ! in_array($reg->status, [RegistrationStatus::WaitingPayment, RegistrationStatus::PaymentVerification, RegistrationStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['invoice' => 'Pendaftaran belum pada tahap pembayaran.']);
        }
        $req = $invoice->trainingRequest;
        if ($req && $req->status === 'cancelled') {
            throw ValidationException::withMessages(['invoice' => 'Permintaan training sudah dibatalkan.']);
        }
    }

    /** Efek bisnis setelah invoice lunas — hanya dipanggil sekali (di dalam transaksi verifikasi yang mengubah status). */
    private function onInvoicePaid(Invoice $invoice, PaymentTransaction $tx, User $actor): void
    {
        $reg = $invoice->registration;
        if ($reg) {
            if ($reg->status === RegistrationStatus::WaitingPayment) {
                $this->registrations->transition($reg, RegistrationStatus::PaymentVerification, $actor, 'Pembayaran dicatat finance');
                $reg->refresh();
            }
            if ($reg->status->canTransitionTo(RegistrationStatus::Confirmed)) {
                $this->registrations->transition($reg, RegistrationStatus::Confirmed, $actor, 'Pembayaran terverifikasi ('.$invoice->invoice_number.')');
            }
        }
        $req = $invoice->trainingRequest;
        if ($req && $req->status === 'approved') {
            $this->requests->markPaid($req, $actor, $tx->code.' / '.$invoice->invoice_number);
        }
        // Roadmap 5 §6 "Payment Success": peserta, finance perusahaan, staf finance VMES (sekali per invoice)
        app(NotificationEvents::class)->paymentSucceeded($invoice->refresh(), $actor);
    }

    public static function rupiah(float $v): string
    {
        return 'Rp '.number_format($v, 0, ',', '.');
    }
}
