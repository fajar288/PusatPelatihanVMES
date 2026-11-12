<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PromoCode;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Finance lanjutan (PRD §5.11): promo/diskon, tagihan termin, ringkasan pendapatan. */
class FinanceService
{
    /** Terapkan kode promo ke pendaftaran (hitung ulang total). */
    public function applyPromo(Registration $registration, string $code, ?User $actor = null): Registration
    {
        $promo = PromoCode::query()->where('code', strtoupper(trim($code)))->first();
        $gross = (float) ($registration->price_quoted ?? 0) * $registration->participants_count;
        if (! $promo || ($reason = $promo->invalidReason($gross, $registration->package_id))) {
            throw ValidationException::withMessages(['promo_code' => $reason ?? 'Kode promo tidak ditemukan.']);
        }

        return DB::transaction(function () use ($registration, $promo, $gross, $actor) {
            if ($registration->promo_code && $registration->promo_code !== $promo->code) {
                PromoCode::query()->where('code', $registration->promo_code)->where('used_count', '>', 0)->decrement('used_count');
            }
            if ($registration->promo_code !== $promo->code) {
                $promo->increment('used_count');
            }
            $discount = $promo->discountFor($gross);
            $before = ['discount' => (float) $registration->discount, 'total' => (float) $registration->total, 'promo_code' => $registration->promo_code];
            $registration->update(['promo_code' => $promo->code, 'discount' => $discount, 'discount_note' => $promo->name, 'total' => max(0, $gross - $discount)]);
            AuditLog::record('registration.promo_applied', $registration, $before, ['promo_code' => $promo->code, 'discount' => $discount, 'total' => (float) $registration->total], $actor);
            app(InvoiceService::class)->syncFromRegistration($registration, $actor);

            return $registration;
        });
    }

    /** Diskon manual (nominal) oleh admin. */
    public function applyManualDiscount(Registration $registration, float $discount, ?string $note, ?User $actor = null): Registration
    {
        $gross = (float) ($registration->price_quoted ?? 0) * $registration->participants_count;
        $discount = max(0, min($discount, $gross));
        $before = ['discount' => (float) $registration->discount, 'total' => (float) $registration->total];
        $registration->update(['discount' => $discount, 'discount_note' => $note, 'promo_code' => null, 'total' => $gross - $discount]);
        AuditLog::record('registration.discount_applied', $registration, $before, ['discount' => $discount, 'note' => $note], $actor);
        app(InvoiceService::class)->syncFromRegistration($registration, $actor);

        return $registration;
    }

    /**
     * Tagihan termin (dipindahkan ke InvoiceService di Roadmap 5C): nilai bersih dibagi n, pajak per termin.
     *
     * @return Invoice[]
     */
    public function createInstallments(Registration $registration, int $count, int $intervalDays = 30, ?string $firstDue = null, ?User $actor = null): array
    {
        return app(InvoiceService::class)->installments($registration, $count, $intervalDays, $firstDue, $actor);
    }

    /**
     * Ringkasan pendapatan dari invoice. verified = dana diterima bersih refund; pending = bukti menunggu verifikasi;
     * outstanding = sisa tagihan terbuka (termasuk kedaluwarsa).
     *
     * @return array{verified: float, pending: float, outstanding: float, by_month: array<string, float>}
     */
    public function revenueSummary(?string $from = null, ?string $to = null): array
    {
        $q = Invoice::query()->whereNotIn('status', ['cancelled']);
        if ($from) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('created_at', '<=', $to);
        }
        $rows = $q->with(['transactions' => fn ($t) => $t->where('status', 'pending')])->get();
        $byMonth = [];
        foreach ($rows->filter(fn (Invoice $i) => (float) $i->amount_paid > 0) as $i) {
            $k = ($i->paid_at ?? $i->updated_at)->format('Y-m');
            $byMonth[$k] = ($byMonth[$k] ?? 0) + (float) $i->amount_paid - (float) $i->amount_refunded;
        }
        ksort($byMonth);

        return [
            'verified' => (float) $rows->sum(fn (Invoice $i) => (float) $i->amount_paid - (float) $i->amount_refunded),
            'pending' => (float) $rows->sum(fn (Invoice $i) => (float) $i->transactions->sum('amount')),
            'outstanding' => (float) $rows->whereIn('status', ['pending', 'failed', 'expired'])->sum(fn (Invoice $i) => $i->outstanding()),
            'by_month' => $byMonth,
        ];
    }
}
