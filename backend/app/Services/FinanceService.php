<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Registration;
use App\Models\User;
use Carbon\Carbon;
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

        return $registration;
    }

    /**
     * Buat tagihan termin: total dibagi n (sisa pembulatan ke termin terakhir), jatuh tempo berjarak `$intervalDays`.
     * Tagihan lama yang belum dibayar diganti.
     *
     * @return Payment[]
     */
    public function createInstallments(Registration $registration, int $count, int $intervalDays = 30, ?string $firstDue = null, ?User $actor = null): array
    {
        $count = max(1, min(12, $count));
        $total = (float) ($registration->total ?? 0);
        if ($total <= 0) {
            throw ValidationException::withMessages(['total' => 'Total pendaftaran belum ditentukan.']);
        }

        return DB::transaction(function () use ($registration, $count, $intervalDays, $firstDue, $total, $actor) {
            $registration->payments()->whereIn('status', ['unpaid', 'rejected'])->delete();
            $paid = (float) $registration->payments()->where('status', 'verified')->sum('amount');
            $remaining = max(0, $total - $paid);
            $base = floor($remaining / $count);
            $due = $firstDue ? Carbon::parse($firstDue) : now()->addDays(3);
            $out = [];
            for ($i = 1; $i <= $count; $i++) {
                $amount = $i === $count ? $remaining - $base * ($count - 1) : $base;
                $out[] = Payment::query()->create([
                    'registration_id' => $registration->id,
                    'invoice_number' => Payment::nextNumber(),
                    'amount' => $amount,
                    'installment_no' => $i,
                    'installment_total' => $count,
                    'description' => $count > 1 ? "Termin {$i} dari {$count}" : null,
                    'due_date' => $due->copy()->addDays($intervalDays * ($i - 1))->toDateString(),
                    'status' => 'unpaid',
                ]);
            }
            AuditLog::record('payment.installments_created', $registration, null, ['count' => $count, 'remaining' => $remaining], $actor);

            return $out;
        });
    }

    /** @return array{verified: float, pending: float, outstanding: float, by_month: array<string, float>} */
    public function revenueSummary(?string $from = null, ?string $to = null): array
    {
        $q = Payment::query();
        if ($from) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('created_at', '<=', $to);
        }
        $rows = $q->get(['amount', 'status', 'verified_at', 'created_at']);
        $byMonth = [];
        foreach ($rows->where('status', 'verified') as $p) {
            $k = ($p->verified_at ?? $p->created_at)->format('Y-m');
            $byMonth[$k] = ($byMonth[$k] ?? 0) + (float) $p->amount;
        }
        ksort($byMonth);

        return [
            'verified' => (float) $rows->where('status', 'verified')->sum('amount'),
            'pending' => (float) $rows->where('status', 'submitted')->sum('amount'),
            'outstanding' => (float) $rows->whereIn('status', ['unpaid', 'rejected'])->sum('amount'),
            'by_month' => $byMonth,
        ];
    }
}
