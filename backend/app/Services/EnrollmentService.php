<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Registration;
use App\Models\User;
use App\Support\Enterprise\IdentityMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Konversi pendaftaran terkonfirmasi → enrollment (PRD §5.5). Idempoten per pendaftaran/peserta.
 * Roadmap 5 (§4.4, §20.5): pendaftaran individu → SELF (tanpa sponsor); kolektif → CORPORATE dengan sponsor
 * organisasi pendaftaran. Peserta dicocokkan ke Member yang ada (tidak membuat akun ganda); yang meragukan masuk
 * review queue; peserta yang sudah terdaftar di batch yang sama ditolak.
 */
class EnrollmentService
{
    public function __construct(private IdentityMatchingService $identity, private OrganizationService $organizations) {}

    /** @return Enrollment[] */
    public function fromRegistration(Registration $registration, ?User $actor = null): array
    {
        return DB::transaction(function () use ($registration, $actor) {
            $created = [];
            $rejected = [];
            $corporate = $registration->type === Registration::TYPE_CORPORATE;
            $sponsor = $corporate ? $this->organizations->forRegistration($registration, $actor) : null;
            $schedule = $registration->schedule;

            $people = $corporate && $registration->participants()->exists()
                ? $registration->participants->map(fn ($p) => ['participant' => $p, 'member_id' => $p->member_id, 'name' => $p->name, 'email' => $p->email, 'phone' => $p->phone])->all()
                : [['participant' => null, 'member_id' => $registration->member_id, 'name' => $registration->name, 'email' => $registration->email, 'phone' => $registration->phone]];

            foreach ($people as $person) {
                $key = ['schedule_id' => $registration->schedule_id, 'registration_id' => $registration->id, 'registration_participant_id' => $person['participant']?->id];
                if ($existing = Enrollment::query()->where($key)->first()) {
                    $created[] = $existing;   // idempoten: konfirmasi ulang tidak membuat enrollment ganda

                    continue;
                }

                $memberId = $person['member_id'];
                $match = null;
                if (! $memberId) {
                    $match = $this->identity->match($person, $sponsor, $schedule);
                    if ($match->outcome === IdentityMatch::USE_EXISTING) {
                        $memberId = ($match->member ?? app(MemberService::class)->createForUser($match->user))->id;
                        $person['participant']?->update(['member_id' => $memberId]);
                    }
                }
                if ($memberId && Enrollment::query()->where('schedule_id', $registration->schedule_id)->where('member_id', $memberId)->where('status', '!=', 'dropped')->exists()) {
                    $rejected[] = $person['name'];
                    AuditLog::record('enrollment.duplicate_rejected', $registration, null, ['member_id' => $memberId, 'name' => $person['name']], $actor);

                    continue;
                }

                $enrollment = Enrollment::query()->create($key + [
                    'member_id' => $memberId,
                    'type' => $corporate ? Enrollment::TYPE_CORPORATE : Enrollment::TYPE_SELF,
                    'sponsor_organization_id' => $sponsor?->id,
                    'participant_name' => $person['name'],
                    'participant_email' => $person['email'],
                    'enrolled_at' => now(),
                ]);
                if (! $memberId && $match) {
                    $this->identity->queueReview($match, $person, $enrollment, $sponsor, $schedule);
                }
                $created[] = $enrollment;
            }

            AuditLog::record('enrollment.created', $registration, null, ['count' => count($created), 'type' => $corporate ? 'corporate' : 'self', 'sponsor_organization_id' => $sponsor?->id, 'duplicates_rejected' => $rejected], $actor);

            return $created;
        });
    }

    /** Batalkan satu peserta — hanya enrollment ini yang berubah (§11 "Corporate membatalkan satu peserta"). */
    public function drop(Enrollment $enrollment, string $reason, ?User $actor = null): Enrollment
    {
        if (in_array($enrollment->status, ['completed', 'dropped'], true)) {
            throw ValidationException::withMessages(['status' => 'Enrollment yang sudah selesai/dibatalkan tidak bisa dibatalkan lagi.']);
        }
        $before = $enrollment->status;
        $enrollment->update(['status' => 'dropped', 'notes' => trim(($enrollment->notes ? $enrollment->notes."\n" : '').'Dibatalkan: '.$reason)]);
        AuditLog::record('enrollment.dropped', $enrollment, ['status' => $before], ['status' => 'dropped', 'reason' => $reason], $actor);

        return $enrollment;
    }
}
