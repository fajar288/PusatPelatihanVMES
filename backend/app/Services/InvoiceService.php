<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Registration;
use App\Models\SiteSetting;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Support\FinanceSettings;
use App\Support\IdDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoice lengkap (PRD Roadmap 5 §5, tahap 5C): item, subtotal, diskon, pajak (tarif dari pengaturan, bawaan 0%), total,
 * jatuh tempo. Sumber: pendaftaran individu/kolektif atau Corporate Training Request. Pembayaran tetap manual.
 */
class InvoiceService
{
    /** Tagihan pendaftaran saat masuk "menunggu pembayaran". Idempoten: invoice aktif/lunas yang ada dipakai ulang. */
    public function forRegistration(Registration $r, ?User $actor = null): ?Invoice
    {
        if ($r->total === null) {
            return null;
        }
        $existing = $r->invoices()->whereIn('status', ['pending', 'failed', 'paid'])->first();
        if ($existing) {
            return $existing;
        }
        $r->loadMissing('schedule.package', 'organization');

        return DB::transaction(function () use ($r, $actor) {
            $invoice = Invoice::query()->create($this->billTo($r) + [
                'invoice_number' => Invoice::nextNumber(),
                'registration_id' => $r->id,
                'organization_id' => $r->type === Registration::TYPE_CORPORATE ? $r->organization_id : null,
                'status' => 'pending',
                'issued_at' => now(),
                'due_date' => today()->addDays(FinanceSettings::all()['due_days'])->toDateString(),
                'notes' => FinanceSettings::all()['invoice_notes'],
                'created_by' => $actor?->id,
            ]);
            $this->fill($invoice, $this->registrationItems($r), (float) $r->discount, $r->discount_note);
            AuditLog::record('invoice.created', $invoice, null, ['number' => $invoice->invoice_number, 'total' => (float) $invoice->total, 'registration' => $r->registration_number], $actor);

            return $invoice;
        });
    }

    /** Tagihan Corporate Training Request dari penawaran yang disetujui. Idempoten. */
    public function forTrainingRequest(TrainingRequest $r, ?User $actor = null): Invoice
    {
        if ($r->quote_total === null) {
            throw ValidationException::withMessages(['quote' => 'Permintaan belum memiliki penawaran.']);
        }
        $existing = Invoice::query()->where('training_request_id', $r->id)->whereIn('status', ['pending', 'failed', 'paid'])->first();
        if ($existing) {
            return $existing;
        }
        $r->loadMissing('organization', 'package', 'unit');

        return DB::transaction(function () use ($r, $actor) {
            $settings = FinanceSettings::all();
            $invoice = Invoice::query()->create($this->organizationBillTo($r->organization) + [
                'invoice_number' => Invoice::nextNumber(),
                'training_request_id' => $r->id,
                'organization_id' => $r->organization_id,
                'status' => 'pending',
                'description' => "Permintaan training {$r->code}",
                'issued_at' => now(),
                'due_date' => today()->addDays($settings['corporate_due_days'])->toDateString(),
                'notes' => trim(implode("\n", array_filter([$r->quote_notes, $settings['invoice_notes']]))) ?: null,
                'created_by' => $actor?->id,
            ]);
            $label = ($r->package?->title ?? 'Program training').' — '.$r->code.($r->unit ? " ({$r->unit->name})" : '');
            $this->fill($invoice, [['description' => $label, 'quantity' => $r->seats, 'unit_price' => (float) $r->price_per_seat]], (float) $r->quote_discount, $r->quote_discount > 0 ? 'penawaran' : null);
            AuditLog::record('invoice.created', $invoice, null, ['number' => $invoice->invoice_number, 'total' => (float) $invoice->total, 'training_request' => $r->code], $actor);

            return $invoice;
        });
    }

    /**
     * Setelah promo/diskon berubah: invoice tunggal pendaftaran yang belum dibayar & belum ada transaksi dihitung ulang.
     */
    public function syncFromRegistration(Registration $r, ?User $actor = null): ?Invoice
    {
        $invoice = $r->invoices()->open()->where('installment_total', 1)->where('amount_paid', 0)->first();
        if (! $invoice || $invoice->transactions()->whereIn('status', ['pending', 'paid'])->exists() || $r->total === null) {
            return null;
        }
        $before = ['total' => (float) $invoice->total];
        $this->fill($invoice, $this->registrationItems($r->loadMissing('schedule.package')), (float) $r->discount, $r->discount_note);
        AuditLog::record('invoice.recalculated', $invoice, $before, ['total' => (float) $invoice->total], $actor);

        return $invoice;
    }

    /**
     * Tagihan termin: sisa nilai bersih pendaftaran dibagi n (sisa pembulatan ke termin terakhir), jatuh tempo berjarak
     * `$intervalDays`; pajak dihitung per termin. Tagihan terbuka yang belum dibayar dibatalkan (bukan dihapus).
     *
     * @return Invoice[]
     */
    public function installments(Registration $r, int $count, int $intervalDays = 30, ?string $firstDue = null, ?User $actor = null): array
    {
        $count = max(1, min(12, $count));
        if ((float) ($r->total ?? 0) <= 0) {
            throw ValidationException::withMessages(['total' => 'Total pendaftaran belum ditentukan.']);
        }
        if ($r->invoices()->open()->whereHas('transactions', fn ($q) => $q->where('status', 'pending'))->exists()) {
            throw ValidationException::withMessages(['invoice' => 'Masih ada bukti pembayaran yang menunggu verifikasi. Verifikasi atau tolak dulu.']);
        }
        if ($r->invoices()->open()->where('amount_paid', '>', 0)->exists()) {
            throw ValidationException::withMessages(['invoice' => 'Ada invoice yang sudah dibayar sebagian. Selesaikan dulu sebelum membuat termin baru.']);
        }
        $r->loadMissing('schedule.package', 'organization');

        return DB::transaction(function () use ($r, $count, $intervalDays, $firstDue, $actor) {
            foreach ($r->invoices()->whereIn('status', ['pending', 'failed', 'expired'])->get() as $old) {
                $this->cancel($old, 'Diganti tagihan termin', $actor);
            }
            $paidNet = (float) $r->invoices()->where('status', 'paid')->get()->sum(fn (Invoice $i) => (float) $i->subtotal - (float) $i->discount);
            $remaining = max(0, (float) $r->total - $paidNet);
            if ($remaining <= 0) {
                throw ValidationException::withMessages(['total' => 'Pendaftaran ini sudah lunas.']);
            }
            $base = floor($remaining / $count);
            $due = $firstDue ? Carbon::parse($firstDue) : today()->addDays(FinanceSettings::all()['due_days']);
            $program = $r->schedule?->package?->title ?? 'Pelatihan';
            $out = [];
            for ($i = 1; $i <= $count; $i++) {
                $amount = $i === $count ? $remaining - $base * ($count - 1) : $base;
                $invoice = Invoice::query()->create($this->billTo($r) + [
                    'invoice_number' => Invoice::nextNumber(),
                    'registration_id' => $r->id,
                    'organization_id' => $r->type === Registration::TYPE_CORPORATE ? $r->organization_id : null,
                    'status' => 'pending',
                    'installment_no' => $i,
                    'installment_total' => $count,
                    'description' => $count > 1 ? "Termin {$i} dari {$count}" : null,
                    'issued_at' => now(),
                    'due_date' => $due->copy()->addDays($intervalDays * ($i - 1))->toDateString(),
                    'notes' => FinanceSettings::all()['invoice_notes'],
                    'created_by' => $actor?->id,
                ]);
                $label = $count > 1 ? "Termin {$i} dari {$count} — {$program} ({$r->registration_number})" : "{$program} ({$r->registration_number})";
                $this->fill($invoice, [['description' => $label, 'quantity' => 1, 'unit_price' => $amount]], 0, null);
                $out[] = $invoice;
            }
            AuditLog::record('invoice.installments_created', $r, null, ['count' => $count, 'remaining' => $remaining, 'invoices' => array_map(fn ($i) => $i->invoice_number, $out)], $actor);

            return $out;
        });
    }

    /** Batalkan invoice yang belum dibayar sama sekali dan tidak punya bukti yang menunggu verifikasi. */
    public function cancel(Invoice $invoice, string $reason, ?User $actor = null): Invoice
    {
        if (! in_array($invoice->status, ['pending', 'failed', 'expired'], true) || (float) $invoice->amount_paid > 0) {
            throw ValidationException::withMessages(['invoice' => "Invoice {$invoice->invoice_number} berstatus \"{$invoice->statusLabel()}\" atau sudah dibayar — tidak bisa dibatalkan (gunakan refund)."]);
        }
        if ($invoice->hasPendingTransaction()) {
            throw ValidationException::withMessages(['invoice' => 'Masih ada bukti pembayaran yang menunggu verifikasi.']);
        }
        $before = $invoice->status;
        $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor?->id, 'cancel_reason' => $reason]);
        AuditLog::record('invoice.cancelled', $invoice, ['status' => $before], ['status' => 'cancelled', 'reason' => $reason], $actor);

        return $invoice;
    }

    /** Perpanjang jatuh tempo; invoice kedaluwarsa dibuka kembali. */
    public function extend(Invoice $invoice, string $dueDate, ?User $actor = null): Invoice
    {
        if (! in_array($invoice->status, ['pending', 'failed', 'expired'], true)) {
            throw ValidationException::withMessages(['invoice' => "Invoice berstatus \"{$invoice->statusLabel()}\" — jatuh tempo tidak bisa diubah."]);
        }
        $due = Carbon::parse($dueDate)->startOfDay();
        if ($due->isBefore(today())) {
            throw ValidationException::withMessages(['due_date' => 'Jatuh tempo baru tidak boleh di masa lalu.']);
        }
        $before = ['status' => $invoice->status, 'due_date' => $invoice->due_date?->toDateString()];
        $invoice->update(['status' => $invoice->status === 'expired' ? 'pending' : $invoice->status, 'due_date' => $due->toDateString(), 'expired_at' => null]);
        AuditLog::record('invoice.due_extended', $invoice, $before, ['status' => $invoice->status, 'due_date' => $due->toDateString()], $actor);

        return $invoice;
    }

    /** Job terjadwal: invoice belum dibayar yang lewat jatuh tempo → expired (kecuali ada bukti menunggu verifikasi). */
    public function expireOverdue(): int
    {
        $n = 0;
        Invoice::query()->open()->whereNotNull('due_date')->whereDate('due_date', '<', today())->where('amount_paid', 0)
            ->whereDoesntHave('transactions', fn ($q) => $q->where('status', 'pending'))
            ->each(function (Invoice $invoice) use (&$n): void {
                $this->expire($invoice);
                $n++;
            });

        return $n;
    }

    public function expire(Invoice $invoice): void
    {
        $before = $invoice->status;
        $invoice->update(['status' => 'expired', 'expired_at' => now()]);
        AuditLog::record('invoice.expired', $invoice, ['status' => $before], ['status' => 'expired', 'due_date' => $invoice->due_date?->toDateString()], null);
    }

    /** Lewat jatuh tempo & belum ada pembayaran sama sekali (dipakai juga saat bukti diunggah sebelum job berjalan). */
    public function isOverdue(Invoice $invoice): bool
    {
        return $invoice->isPayable() && $invoice->due_date !== null && $invoice->due_date->isBefore(today()) && (float) $invoice->amount_paid <= 0;
    }

    /**
     * Isi ulang item & hitung subtotal − diskon + pajak = total. Pajak dibulatkan ke rupiah.
     *
     * @param  list<array{description:string, quantity:int|float, unit_price:float}>  $items
     */
    public function fill(Invoice $invoice, array $items, float $discount, ?string $discountNote): Invoice
    {
        $invoice->items()->delete();
        $subtotal = 0.0;
        foreach (array_values($items) as $i => $item) {
            $amount = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            $subtotal += $amount;
            $invoice->items()->create(['description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'amount' => $amount, 'sort' => $i]);
        }
        $discount = max(0, min($discount, $subtotal));
        $settings = FinanceSettings::all();
        $rate = $settings['tax_rate'];
        $tax = round(($subtotal - $discount) * $rate / 100);
        $invoice->update([
            'subtotal' => $subtotal, 'discount' => $discount, 'discount_note' => $discount > 0 ? $discountNote : null,
            'tax_rate' => $rate, 'tax_label' => $rate > 0 ? $settings['tax_label'] : null, 'tax_amount' => $tax,
            'total' => $subtotal - $discount + $tax,
        ]);

        return $invoice->refresh();
    }

    /** PDF invoice (dompdf, tanpa sumber remote). */
    public function pdf(Invoice $invoice): string
    {
        $invoice->loadMissing('items', 'transactions', 'registration', 'trainingRequest');

        return Pdf::loadView('invoices.pdf', [
            'inv' => $invoice,
            'site' => (array) SiteSetting::get('site', []),
            'logo' => $this->pdfLogo(),
            'accounts' => PaymentAccount::query()->shown()->get(),
            'rp' => fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'),
            'date' => fn ($d) => IdDate::long($d),
        ])->setPaper('a4')->setOption('isRemoteEnabled', false)->setOption('isFontSubsettingEnabled', true)->output();
    }

    /** Logo kecil (lebar 360 px) untuk PDF — logo asli 1600 px membuat tiap PDF ±1 MB. Disimpan sekali di storage. */
    private function pdfLogo(): ?string
    {
        $src = public_path('images/logo.jpeg');
        if (! file_exists($src)) {
            return null;
        }
        $small = storage_path('app/invoice-logo.jpg');
        if (! file_exists($small) && function_exists('imagecreatefromjpeg') && ($img = @imagecreatefromjpeg($src))) {
            $resized = imagescale($img, 360);
            if ($resized) {
                imagejpeg($resized, $small, 85);
            }
        }
        $path = file_exists($small) ? $small : $src;

        return 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($path));
    }

    /** @return list<array{description:string, quantity:int|float, unit_price:float}> */
    private function registrationItems(Registration $r): array
    {
        $program = $r->schedule?->package?->title ?? 'Pelatihan';
        $batch = $r->schedule ? ' — batch '.$r->schedule->code : '';
        if ($r->price_quoted === null) {
            return [['description' => "{$program}{$batch} ({$r->registration_number})", 'quantity' => 1, 'unit_price' => (float) $r->total + (float) $r->discount]];
        }

        return [['description' => "{$program}{$batch} ({$r->registration_number})", 'quantity' => max(1, (int) $r->participants_count), 'unit_price' => (float) $r->price_quoted]];
    }

    /** @return array<string, mixed> */
    private function billTo(Registration $r): array
    {
        if ($r->type === Registration::TYPE_CORPORATE && $r->organization) {
            $billTo = $this->organizationBillTo($r->organization);
            $billTo['bill_to_email'] ??= $r->email;

            return $billTo;
        }

        return ['bill_to_name' => $r->name, 'bill_to_email' => $r->email, 'bill_to_company' => $r->company, 'bill_to_address' => null, 'bill_to_tax_id' => null];
    }

    /** @return array<string, mixed> */
    private function organizationBillTo($org): array
    {
        return [
            'bill_to_name' => $org->invoice_name ?: $org->legal_name,
            'bill_to_email' => $org->invoice_email ?: ($org->email ?: $org->contact_email),
            'bill_to_company' => $org->legal_name,
            'bill_to_address' => $org->invoice_address ?: trim(($org->address ?? '').($org->city ? ', '.$org->city : ''), ', ') ?: null,
            'bill_to_tax_id' => $org->tax_id,
        ];
    }
}
