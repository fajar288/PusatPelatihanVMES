<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\RegistrationReceived;
use App\Notifications\RegistrationStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Alur pendaftaran (PRD §5.5): buat pendaftaran, ubah status dengan validasi transisi, catat audit, kirim notifikasi. */
class RegistrationService
{
    public function __construct(private readonly MemberService $members, private readonly EnrollmentService $enrollments) {}

    /**
     * @param  array<string, mixed>  $data  hasil StoreRegistrationRequest
     * @param  array<int, array<string, mixed>>  $participants  untuk kolektif
     */
    public function create(array $data, TrainingSchedule $schedule, ?User $user = null, array $participants = [], string $source = 'web'): Registration
    {
        $type = $data['type'] ?? Registration::TYPE_INDIVIDUAL;
        $count = $type === Registration::TYPE_CORPORATE ? max(1, (int) ($data['participants_count'] ?? count($participants) ?: 1)) : 1;
        $price = $schedule->effectivePrice();

        return DB::transaction(function () use ($data, $schedule, $user, $participants, $source, $type, $count, $price) {
            // satu akun = satu member: pengguna login yang belum punya profil member dibuatkan otomatis
            $member = $user ? ($user->member ?? $this->members->createForUser($user)) : null;

            $registration = Registration::query()->create([
                'registration_number' => Registration::nextNumber(),
                'schedule_id' => $schedule->id,
                'package_id' => $schedule->package_id,
                'member_id' => $member?->id,
                'user_id' => $user?->id,
                'type' => $type,
                'status' => RegistrationStatus::Pending,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'company' => $data['company'] ?? null,
                'position' => $data['position'] ?? null,
                'participants_count' => $count,
                'notes' => $data['notes'] ?? null,
                'documents' => $data['documents'] ?? null,
                'consent' => (bool) ($data['consent'] ?? false),
                'price_quoted' => $price,
                'discount' => 0,
                'total' => $price !== null ? $price * $count : null,
                'source' => $source,
            ]);

            foreach ($participants as $p) {
                $registration->participants()->create([
                    'name' => $p['name'], 'email' => $p['email'] ?? null, 'phone' => $p['phone'] ?? null, 'position' => $p['position'] ?? null,
                ]);
            }

            AuditLog::record('registration.created', $registration, null, ['status' => 'pending', 'number' => $registration->registration_number], $user);
            $registration->notify(new RegistrationReceived($registration));

            return $registration;
        });
    }

    /** Ubah status dengan validasi transisi; buat tagihan saat masuk "menunggu pembayaran". */
    public function transition(Registration $registration, RegistrationStatus $to, ?User $actor = null, ?string $note = null): Registration
    {
        $from = $registration->status;
        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => "Transisi {$from->label()} → {$to->label()} tidak diizinkan."]);
        }

        return DB::transaction(function () use ($registration, $from, $to, $actor, $note) {
            $registration->forceFill([
                'status' => $to,
                'status_note' => $note ?? $registration->status_note,
                'reviewed_by' => $actor?->id ?? $registration->reviewed_by,
                'reviewed_at' => now(),
                'confirmed_at' => $to === RegistrationStatus::Confirmed ? now() : $registration->confirmed_at,
            ])->save();

            if ($to === RegistrationStatus::WaitingPayment && $registration->total !== null && $registration->payments()->whereIn('status', ['unpaid', 'submitted'])->doesntExist()) {
                Payment::query()->create([
                    'registration_id' => $registration->id,
                    'invoice_number' => Payment::nextNumber(),
                    'amount' => $registration->total,
                    'due_date' => now()->addDays(3)->toDateString(),
                    'status' => 'unpaid',
                ]);
            }

            if ($to === RegistrationStatus::Confirmed) {
                $registration->schedule()->increment('registered_count', $registration->participants_count);
                $this->activateMember($registration);
                $this->enrollments->fromRegistration($registration, $actor);
            }

            AuditLog::record('registration.status_changed', $registration, ['status' => $from->value], ['status' => $to->value, 'note' => $note], $actor);
            $registration->notify(new RegistrationStatusChanged($registration, $from));

            return $registration->refresh();
        });
    }

    /** Pendaftar yang sudah punya akun → member aktif (konversi pendaftar → member, PRD §5.5). */
    private function activateMember(Registration $registration): void
    {
        $member = $registration->member ?? ($registration->user_id ? Member::query()->where('user_id', $registration->user_id)->first() : null);
        if ($member && $member->status === 'registered') {
            $member->update(['status' => 'active']);
        }
    }
}
