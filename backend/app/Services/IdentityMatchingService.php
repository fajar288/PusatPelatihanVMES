<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\IdentityReview;
use App\Models\Member;
use App\Models\Organization;
use App\Models\RegistrationParticipant;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Support\Enterprise\IdentityMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Identity matching & duplicate prevention (PRD Roadmap 5 §20.5, §4.3 "Existing Member: tidak membuat akun kedua").
 *
 * | Kondisi                                   | Hasil                                             |
 * | Email terverifikasi cocok                 | USE_EXISTING                                      |
 * | Nomor member cocok                        | USE_EXISTING                                      |
 * | Nomor HP cocok / email belum terverifikasi| CONFIRM (kandidat, perlu konfirmasi)               |
 * | Employee ID cocok di organisasi           | EXISTING_EMPLOYMENT (tawarkan employment yang ada) |
 * | Tidak ditemukan                           | CREATE_NEW (undangan/member baru sesuai kebijakan) |
 * | Sudah terdaftar di batch yang sama        | DUPLICATE (tolak)                                 |
 * | Data meragukan (konflik/tidak lengkap)    | REVIEW (review queue, jangan buat akun)           |
 */
class IdentityMatchingService
{
    public function __construct(private MemberService $members) {}

    /**
     * @param  array{name?:?string, email?:?string, phone?:?string, member_number?:?string, employee_id?:?string}  $input
     */
    public function match(array $input, ?Organization $organization = null, ?TrainingSchedule $schedule = null): IdentityMatch
    {
        $in = self::clean($input);
        $strong = [];   // member_id => matched_by
        $weak = [];     // member_id => matched_by
        $user = null;
        $employment = null;

        if ($in['member_number'] !== null) {
            $m = Member::query()->where('member_number', $in['member_number'])->first();
            if (! $m) {
                return new IdentityMatch(IdentityMatch::REVIEW, reason: 'unknown_member_number');
            }
            $strong[$m->id] = 'member_number';
        }

        if ($in['email'] !== null) {
            $u = User::query()->whereRaw('LOWER(email) = ?', [$in['email']])->first();
            if ($u) {
                $m = $u->member;
                if ($u->email_verified_at !== null) {
                    $user = $u;
                    if ($m) {
                        $strong[$m->id] ??= 'verified_email';
                    } elseif ($strong === []) {
                        // akun terverifikasi tanpa profil member → member dibuat untuk akun itu (bukan akun baru)
                        return $this->withDuplicateCheck(new IdentityMatch(IdentityMatch::USE_EXISTING, user: $u, reason: 'verified_email'), $schedule);
                    }
                } elseif ($m) {
                    $weak[$m->id] ??= 'unverified_email';
                }
            }
        }

        if ($in['phone'] !== null) {
            foreach (Member::query()->whereNotNull('phone')->get(['id', 'phone']) as $m) {
                if (self::phone($m->phone) === $in['phone']) {
                    $weak[$m->id] ??= 'phone_match';
                }
            }
        }

        if ($organization && $in['employee_id'] !== null) {
            $employment = EmploymentRelationship::query()->where('organization_id', $organization->id)
                ->where('employee_id', $in['employee_id'])->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->first();
            if ($employment) {
                $weak[$employment->member_id] ??= 'employee_id_match';
            }
        }

        $candidates = $this->candidates($strong + $weak);

        if (count($strong) > 1) {
            return new IdentityMatch(IdentityMatch::REVIEW, candidates: $candidates, reason: 'conflict');
        }
        if (count($strong) === 1) {
            $id = array_key_first($strong);
            if (array_diff(array_keys($weak), [$id]) !== []) {
                return new IdentityMatch(IdentityMatch::REVIEW, candidates: $candidates, reason: 'conflict');
            }
            $member = Member::query()->with('user')->find($id);

            return $this->withDuplicateCheck(new IdentityMatch(IdentityMatch::USE_EXISTING, member: $member, user: $member?->user, candidates: $candidates, reason: $strong[$id], employment: $employment), $schedule);
        }
        if (count($weak) > 1) {
            return new IdentityMatch(IdentityMatch::REVIEW, candidates: $candidates, reason: 'conflict');
        }
        if (count($weak) === 1) {
            $id = array_key_first($weak);
            $member = Member::query()->find($id);
            $outcome = $weak[$id] === 'employee_id_match' ? IdentityMatch::EXISTING_EMPLOYMENT : IdentityMatch::CONFIRM;

            return new IdentityMatch($outcome, member: $member, candidates: $candidates, reason: $weak[$id], employment: $employment);
        }
        if ($in['email'] === null && $in['phone'] === null) {
            return new IdentityMatch(IdentityMatch::REVIEW, reason: 'incomplete');
        }

        return new IdentityMatch(IdentityMatch::CREATE_NEW, reason: 'not_found');
    }

    /**
     * Cocokkan peserta sebuah enrollment yang belum punya member (pendaftaran kolektif / tamu, backfill).
     * Hanya hasil yakin (USE_EXISTING) yang ditautkan otomatis; selain itu masuk review queue.
     */
    public function resolveEnrollment(Enrollment $enrollment, ?User $actor = null): IdentityMatch
    {
        $input = $this->enrollmentInput($enrollment);
        $schedule = $enrollment->schedule;
        $result = $this->match($input, $enrollment->sponsor, $schedule);

        if ($result->outcome === IdentityMatch::USE_EXISTING) {
            $member = $result->member ?? $this->members->createForUser($result->user);
            if ($this->alreadyEnrolled($member, $schedule, $enrollment->id)) {
                $result = new IdentityMatch(IdentityMatch::DUPLICATE, member: $member, candidates: $result->candidates, reason: 'conflict');
            } else {
                $enrollment->update(['member_id' => $member->id]);
                $enrollment->registrationParticipant?->update(['member_id' => $member->id]);
                AuditLog::record('identity.matched', $enrollment, null, ['member_id' => $member->id, 'matched_by' => $result->reason], $actor);
                IdentityReview::query()->pending()->where('subject_type', $enrollment->getMorphClass())->where('subject_id', $enrollment->id)
                    ->update(['status' => 'resolved', 'resolution' => 'linked', 'resolved_member_id' => $member->id, 'resolved_by' => $actor?->id, 'resolved_at' => now(), 'note' => 'Otomatis: pencocokan ulang menemukan member terverifikasi']);

                return $result;
            }
        }

        $this->queueReview($result, $input, $enrollment, $enrollment->sponsor, $schedule);

        return $result;
    }

    public function queueReview(IdentityMatch $result, array $input, ?Model $subject, ?Organization $organization, ?TrainingSchedule $schedule): IdentityReview
    {
        $reason = match ($result->outcome) {
            IdentityMatch::CREATE_NEW => 'not_found',
            IdentityMatch::DUPLICATE => 'conflict',
            default => $result->reason ?? 'conflict',
        };
        $attrs = ['reason' => $reason, 'input' => self::clean($input) + ['name' => $input['name'] ?? null], 'candidates' => $result->candidates,
            'organization_id' => $organization?->id, 'schedule_id' => $schedule?->id];

        $existing = $subject ? IdentityReview::query()->pending()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->first() : null;
        if ($existing) {
            $existing->update($attrs);

            return $existing;
        }

        return IdentityReview::query()->create($attrs + ['subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(), 'status' => 'pending']);
    }

    /** Admin menautkan review ke member yang ada. */
    public function linkReview(IdentityReview $review, Member $member, User $actor, ?string $note = null): IdentityReview
    {
        return DB::transaction(function () use ($review, $member, $actor, $note) {
            $this->assertPending($review);
            $this->applyMember($review, $member);
            $review->update(['status' => 'resolved', 'resolution' => 'linked', 'resolved_member_id' => $member->id, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'note' => $note]);
            AuditLog::record('identity.review_linked', $review, null, ['member_id' => $member->id, 'subject' => $review->subject_type.'#'.$review->subject_id], $actor);

            return $review;
        });
    }

    /** Admin memutuskan membuat akun + member baru (keputusan manusia, bukan otomatis). */
    public function createMemberForReview(IdentityReview $review, User $actor, ?string $note = null): IdentityReview
    {
        return DB::transaction(function () use ($review, $actor, $note) {
            $this->assertPending($review);
            $email = $review->input['email'] ?? null;
            if (! $email) {
                throw ValidationException::withMessages(['email' => 'Email peserta wajib ada untuk membuat akun baru. Lengkapi data atau tautkan ke member yang ada.']);
            }
            if (User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->exists()) {
                throw ValidationException::withMessages(['email' => 'Email ini sudah dipakai akun lain — tautkan ke member yang ada.']);
            }
            $user = User::query()->create([
                'name' => $review->input['name'] ?: Str::before($email, '@'),
                'email' => strtolower($email),
                'password' => Hash::make(Str::random(40)),
                'role' => User::ROLE_STUDENT,
            ]);
            $member = $this->members->createForUser($user, ['phone' => $review->input['phone'] ?? null]);
            $this->applyMember($review, $member);
            $review->update(['status' => 'resolved', 'resolution' => 'created', 'resolved_member_id' => $member->id, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'note' => $note]);
            AuditLog::record('identity.review_member_created', $review, null, ['member_id' => $member->id, 'user_id' => $user->id], $actor);

            return $review;
        });
    }

    public function dismissReview(IdentityReview $review, User $actor, string $note): IdentityReview
    {
        $this->assertPending($review);
        $review->update(['status' => 'dismissed', 'resolution' => 'dismissed', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'note' => $note]);
        AuditLog::record('identity.review_dismissed', $review, null, ['note' => $note], $actor);

        return $review;
    }

    public function alreadyEnrolled(Member $member, ?TrainingSchedule $schedule, ?int $exceptEnrollmentId = null): bool
    {
        return $schedule !== null && Enrollment::query()->where('schedule_id', $schedule->id)->where('member_id', $member->id)
            ->where('status', '!=', 'dropped')->when($exceptEnrollmentId, fn ($q) => $q->whereKeyNot($exceptEnrollmentId))->exists();
    }

    /** @return array{name:?string,email:?string,phone:?string,member_number:?string,employee_id:?string} */
    public static function clean(array $input): array
    {
        $s = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;

        return [
            'name' => $s($input['name'] ?? null),
            'email' => ($e = $s($input['email'] ?? null)) ? strtolower($e) : null,
            'phone' => ($p = $s($input['phone'] ?? null)) ? self::phone($p) : null,
            'member_number' => ($n = $s($input['member_number'] ?? null)) ? strtoupper($n) : null,
            'employee_id' => $s($input['employee_id'] ?? null),
        ];
    }

    /** Normalisasi nomor HP Indonesia: hanya digit, awalan 0 → 62. */
    public static function phone(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if ($d === '' || strlen($d) < 8) {
            return null;
        }

        return str_starts_with($d, '0') ? '62'.substr($d, 1) : $d;
    }

    private function withDuplicateCheck(IdentityMatch $m, ?TrainingSchedule $schedule): IdentityMatch
    {
        if ($m->member && $this->alreadyEnrolled($m->member, $schedule)) {
            return new IdentityMatch(IdentityMatch::DUPLICATE, member: $m->member, user: $m->user, candidates: $m->candidates, reason: $m->reason);
        }

        return $m;
    }

    /** @param array<int,string> $matches @return list<array{member_id:int, member_number:string, name:string, matched_by:string}> */
    private function candidates(array $matches): array
    {
        if ($matches === []) {
            return [];
        }
        $members = Member::query()->with('user:id,name')->whereIn('id', array_keys($matches))->get()->keyBy('id');

        return collect($matches)->map(fn (string $by, int $id) => [
            'member_id' => $id, 'member_number' => (string) $members[$id]?->member_number, 'name' => (string) $members[$id]?->user?->name, 'matched_by' => $by,
        ])->values()->all();
    }

    private function enrollmentInput(Enrollment $e): array
    {
        $p = $e->registrationParticipant;
        $r = $e->registration;
        $individual = $r && $r->type !== 'corporate';

        return [
            'name' => $p?->name ?? $e->participant_name,
            'email' => $p?->email ?? $e->participant_email ?? ($individual ? $r->email : null),
            'phone' => $p?->phone ?? ($individual ? $r->phone : null),
        ];
    }

    private function applyMember(IdentityReview $review, Member $member): void
    {
        $subject = $review->subject;
        if ($subject instanceof Enrollment) {
            if ($this->alreadyEnrolled($member, $subject->schedule, $subject->id)) {
                throw ValidationException::withMessages(['member' => 'Member ini sudah terdaftar di batch yang sama — duplikat enrollment ditolak.']);
            }
            $subject->update(['member_id' => $member->id]);
            $subject->registrationParticipant?->update(['member_id' => $member->id]);
        } elseif ($subject instanceof RegistrationParticipant) {
            $subject->update(['member_id' => $member->id]);
        }
    }

    private function assertPending(IdentityReview $review): void
    {
        if ($review->status !== 'pending') {
            throw ValidationException::withMessages(['review' => 'Tinjauan ini sudah diselesaikan.']);
        }
    }
}
