<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CorporateUser;
use App\Models\OrganizationUnit;
use App\Models\RequestSeat;
use App\Models\TrainingPackage;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\TrainingRequestUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Siklus Corporate Training Request (PRD Roadmap 5 §4.2, §20.3, §20.13):
 * Request (HR) → Review & quotation (VMES) → Approval (perusahaan, opsional per organisasi) → Payment (finance,
 * pembayaran manual diverifikasi VMES) → Assignment (HR, kapan saja) → Delivery (jadwal VMES) → Completion.
 * Kursi 01..N dibuat saat request disimpan; kursi TIDAK membuat Member. Semua transisi diaudit.
 */
class TrainingRequestService
{
    public function __construct(private ParticipantAssignmentService $assignments, private InvoiceService $invoices) {}

    /** @param array{package_id:int, seats:int, delivery?:string, target_date?:mixed, location?:?string, unit_id?:?int, trainer_requirements?:?string, notes?:?string} $data */
    public function create(CorporateUser $cu, array $data, bool $submit = false): TrainingRequest
    {
        $this->assertCan($cu, 'manage_requests');
        $data = $this->validated($cu, $data);

        return DB::transaction(function () use ($cu, $data, $submit) {
            $r = TrainingRequest::query()->create($data + [
                'code' => TrainingRequest::nextCode(),
                'organization_id' => $cu->organization_id,
                'status' => 'draft',
                'requested_by' => $cu->user_id,
            ]);
            $this->syncSeats($r, (int) $data['seats']);
            AuditLog::record('training_request.created', $r, null, $r->only(['code', 'organization_id', 'package_id', 'seats', 'delivery', 'target_date', 'unit_id']), $cu->user);

            return $submit ? $this->submit($r, $cu) : $r;
        });
    }

    /** Ubah draf (jumlah kursi hanya boleh dikurangi sampai kursi terisi terakhir). */
    public function update(TrainingRequest $r, CorporateUser $cu, array $data): TrainingRequest
    {
        $this->assertCan($cu, 'manage_requests');
        $this->assertStatus($r, ['draft']);
        $data = $this->validated($cu, $data + $r->only(['package_id', 'seats', 'delivery']));

        return DB::transaction(function () use ($r, $cu, $data) {
            $before = $r->only(array_keys($data));
            $r->update($data);
            $this->syncSeats($r, (int) $data['seats']);
            $r->requestSeats()->where('status', 'reserved')->update(['unit_id' => $r->unit_id]);
            AuditLog::record('training_request.updated', $r, $before, $r->only(array_keys($data)), $cu->user);

            return $r;
        });
    }

    public function submit(TrainingRequest $r, CorporateUser $cu): TrainingRequest
    {
        $this->assertCan($cu, 'manage_requests');

        return $this->move($r, ['draft'], 'submitted', ['submitted_at' => now()], $cu->user, notifyVmes: true);
    }

    /** VMES (sales/training manager) membuat penawaran. */
    public function quote(TrainingRequest $r, User $actor, float $pricePerSeat, float $discount = 0, mixed $validUntil = null, ?string $notes = null): TrainingRequest
    {
        if ($pricePerSeat < 0 || $discount < 0) {
            throw ValidationException::withMessages(['price_per_seat' => 'Harga dan diskon tidak boleh negatif.']);
        }
        $total = max(0, round($pricePerSeat * $r->seats - $discount, 2));

        return $this->move($r, ['submitted', 'quoted'], 'quoted', [
            'price_per_seat' => $pricePerSeat, 'quote_discount' => $discount, 'quote_total' => $total,
            'quote_valid_until' => $validUntil ? Carbon::parse($validUntil)->toDateString() : today()->addDays(14)->toDateString(),
            'quote_notes' => $notes, 'quoted_by' => $actor->id, 'quoted_at' => now(),
        ], $actor, notifyCorporate: true);
    }

    /** Perusahaan menerima penawaran → APPROVAL (bila organisasi mewajibkan persetujuan) atau langsung APPROVED. */
    public function acceptQuote(TrainingRequest $r, CorporateUser $cu): TrainingRequest
    {
        $this->assertCan($cu, 'manage_requests');
        if ($r->quote_valid_until && $r->quote_valid_until->isBefore(today())) {
            throw ValidationException::withMessages(['quote' => 'Penawaran sudah kedaluwarsa. Minta VMES memperbarui penawaran.']);
        }
        $needsApproval = $r->organization->requiresApproval() && ! $cu->allows('approve_requests');
        $extra = ['quote_accepted_by' => $cu->user_id, 'quote_accepted_at' => now()];
        if (! $needsApproval) {
            $extra += ['approved_by' => $cu->user_id, 'approved_at' => now()];
        }

        return $this->move($r, ['quoted'], $needsApproval ? 'approval' : 'approved', $extra, $cu->user, notifyVmes: true, notifyCorporate: $needsApproval);
    }

    /** Pengguna berwenang perusahaan (owner) menyetujui. */
    public function approve(TrainingRequest $r, CorporateUser $cu, ?string $note = null): TrainingRequest
    {
        $this->assertCan($cu, 'approve_requests');

        return $this->move($r, ['approval'], 'approved', ['approved_by' => $cu->user_id, 'approved_at' => now(), 'approval_note' => $note], $cu->user, notifyVmes: true);
    }

    /** Dipanggil PaymentService saat invoice permintaan ini lunas diverifikasi finance VMES (Roadmap 5C). */
    public function markPaid(TrainingRequest $r, User $actor, ?string $reference = null): TrainingRequest
    {
        return $this->move($r, ['approved'], 'paid', ['paid_at' => now(), 'paid_confirmed_by' => $actor->id, 'payment_reference' => $reference], $actor, notifyCorporate: true);
    }

    /** VMES menetapkan batch; kursi yang sudah berisi peserta langsung menjadi enrollment CORPORATE bersponsor. */
    public function schedule(TrainingRequest $r, TrainingSchedule $schedule, User $actor): TrainingRequest
    {
        if ($schedule->package_id !== $r->package_id) {
            throw ValidationException::withMessages(['schedule_id' => 'Batch harus untuk program yang sama dengan permintaan.']);
        }

        return DB::transaction(function () use ($r, $schedule, $actor) {
            $this->move($r, ['paid', 'scheduled'], 'scheduled', ['schedule_id' => $schedule->id, 'scheduled_at' => now()], $actor, notifyCorporate: true);
            $r->refresh();
            foreach ($r->requestSeats()->where('status', 'assigned')->get() as $seat) {
                $this->assignments->enrollSeat($seat, $actor);
            }

            return $r->refresh();
        });
    }

    public function cancel(TrainingRequest $r, User $actor, string $reason, ?CorporateUser $cu = null): TrainingRequest
    {
        if ($cu) {
            $this->assertCan($cu, 'manage_requests');
        }
        $allowed = $cu ? ['draft', 'submitted', 'quoted', 'approval'] : ['draft', 'submitted', 'quoted', 'approval', 'approved', 'paid', 'scheduled'];

        return DB::transaction(function () use ($r, $actor, $reason, $allowed) {
            $this->move($r, $allowed, 'cancelled', ['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason], $actor, notifyVmes: true, notifyCorporate: true);
            foreach ($r->requestSeats()->whereIn('status', ['invited', 'assigned', 'enrolled'])->get() as $seat) {
                $this->assignments->releaseSeat($seat, $actor, 'Permintaan dibatalkan: '.$reason);
            }

            return $r;
        });
    }

    /** Sinkron status dengan jadwal batch (dijalankan terjadwal): SCHEDULED → IN_PROGRESS → COMPLETED. */
    public function syncWithSchedules(): int
    {
        $n = 0;
        TrainingRequest::query()->whereIn('status', ['scheduled', 'in_progress'])->with('schedule')->each(function (TrainingRequest $r) use (&$n): void {
            $s = $r->schedule;
            if (! $s) {
                return;
            }
            $to = match (true) {
                $s->status === 'completed' || $s->end_date->isBefore(today()) => 'completed',
                ! $s->start_date->isAfter(today()) => 'in_progress',
                default => null,
            };
            if ($to && $to !== $r->status) {
                $this->move($r, ['scheduled', 'in_progress'], $to, [], null);
                $n++;
            }
        });

        return $n;
    }

    private function move(TrainingRequest $r, array $from, string $to, array $extra, ?User $actor, bool $notifyVmes = false, bool $notifyCorporate = false): TrainingRequest
    {
        $this->assertStatus($r, $from);
        $before = $r->status;
        $r->update(['status' => $to] + $extra);
        if ($to === 'approved') {
            $this->invoices->forTrainingRequest($r, $actor);   // invoice dari penawaran yang disetujui (5C)
        }
        if ($to === 'cancelled') {
            $r->invoices()->whereIn('status', ['pending', 'failed', 'expired'])->where('amount_paid', 0)->get()
                ->each(fn ($inv) => $inv->hasPendingTransaction() ? null : $this->invoices->cancel($inv, 'Permintaan training dibatalkan', $actor));
        }
        AuditLog::record('training_request.'.$to, $r, ['status' => $before], ['status' => $to] + array_intersect_key($extra, array_flip(['price_per_seat', 'quote_total', 'schedule_id', 'cancel_reason', 'payment_reference', 'approval_note'])), $actor);

        if ($notifyCorporate) {
            $users = CorporateUser::query()->active()->where('organization_id', $r->organization_id)->whereIn('role', ['owner', 'hr_admin', 'finance'])->with('user')->get()->pluck('user')->filter();
            Notification::send($users, new TrainingRequestUpdated($r, $before));
        }
        if ($notifyVmes) {
            $staff = User::query()->whereIn('role', [User::ROLE_ADMINISTRATOR, User::ROLE_MARKETING])->get();
            Notification::send($staff, new TrainingRequestUpdated($r, $before, forVmes: true));
        }

        return $r;
    }

    private function validated(CorporateUser $cu, array $data): array
    {
        $out = array_intersect_key($data, array_flip(['package_id', 'seats', 'delivery', 'target_date', 'location', 'unit_id', 'trainer_requirements', 'notes']));
        if (! TrainingPackage::query()->published()->whereKey($out['package_id'] ?? 0)->exists()) {
            throw ValidationException::withMessages(['package_id' => 'Program training tidak ditemukan.']);
        }
        $seats = (int) ($out['seats'] ?? 0);
        if ($seats < 1 || $seats > 500) {
            throw ValidationException::withMessages(['seats' => 'Jumlah kursi 1–500.']);
        }
        if (! array_key_exists($out['delivery'] ?? 'onsite', TrainingRequest::DELIVERY)) {
            throw ValidationException::withMessages(['delivery' => 'Metode delivery tidak dikenal.']);
        }
        if (! empty($out['unit_id'])) {
            $unit = OrganizationUnit::query()->find($out['unit_id']);
            if (! $unit || $unit->organization_id !== $cu->organization_id) {
                throw ValidationException::withMessages(['unit_id' => 'Unit bukan milik organisasi ini.']);
            }
            if (($scope = $cu->unitScope()) !== null && ! in_array($unit->id, $scope, true)) {
                throw ValidationException::withMessages(['unit_id' => 'Unit di luar batas akses Anda.']);
            }
        } elseif ($cu->unit_id) {
            $out['unit_id'] = $cu->unit_id;   // pengguna dengan batas unit selalu meminta untuk unitnya
        }

        return $out;
    }

    private function syncSeats(TrainingRequest $r, int $seats): void
    {
        $existing = $r->requestSeats()->count();
        for ($i = $existing + 1; $i <= $seats; $i++) {
            RequestSeat::query()->create(['training_request_id' => $r->id, 'seat_no' => $i, 'status' => 'reserved', 'unit_id' => $r->unit_id]);
        }
        if ($seats < $existing) {
            $taken = $r->requestSeats()->where('seat_no', '>', $seats)->where('status', '!=', 'reserved')->exists();
            if ($taken) {
                throw ValidationException::withMessages(['seats' => 'Kursi yang sudah berisi peserta tidak bisa dihapus. Batalkan pesertanya dulu.']);
            }
            $r->requestSeats()->where('seat_no', '>', $seats)->delete();
        }
    }

    private function assertStatus(TrainingRequest $r, array $allowed): void
    {
        if (! in_array($r->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Permintaan berstatus \"{$r->label()}\" — aksi ini tidak diizinkan."]);
        }
    }

    private function assertCan(CorporateUser $cu, string $capability): void
    {
        if (! $cu->isActive() || ! $cu->allows($capability)) {
            throw ValidationException::withMessages(['role' => 'Peran Anda di perusahaan tidak mengizinkan aksi ini.']);
        }
    }
}
