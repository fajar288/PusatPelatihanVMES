<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Konversi pendaftaran terkonfirmasi → enrollment (PRD §5.5). Idempoten per pendaftaran. */
class EnrollmentService
{
    /** @return Enrollment[] */
    public function fromRegistration(Registration $registration, ?User $actor = null): array
    {
        return DB::transaction(function () use ($registration, $actor) {
            $created = [];
            if ($registration->type === Registration::TYPE_CORPORATE && $registration->participants()->exists()) {
                foreach ($registration->participants as $p) {
                    $created[] = Enrollment::query()->firstOrCreate(
                        ['schedule_id' => $registration->schedule_id, 'registration_participant_id' => $p->id],
                        ['registration_id' => $registration->id, 'member_id' => $p->member_id, 'participant_name' => $p->name, 'participant_email' => $p->email, 'enrolled_at' => now()],
                    );
                }
            } else {
                $created[] = Enrollment::query()->firstOrCreate(
                    ['schedule_id' => $registration->schedule_id, 'registration_id' => $registration->id, 'registration_participant_id' => null],
                    ['member_id' => $registration->member_id, 'participant_name' => $registration->name, 'participant_email' => $registration->email, 'enrolled_at' => now()],
                );
            }
            AuditLog::record('enrollment.created', $registration, null, ['count' => count($created)], $actor);

            return $created;
        });
    }
}
