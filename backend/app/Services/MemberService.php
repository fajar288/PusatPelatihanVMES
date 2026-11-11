<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Satu akun = satu member (PRD §5.6). */
class MemberService
{
    /** @param  array<string, mixed>  $profile */
    public function createForUser(User $user, array $profile = []): Member
    {
        return DB::transaction(function () use ($user, $profile) {
            $member = Member::query()->where('user_id', $user->id)->first() ?? Member::query()->create([
                'user_id' => $user->id,
                'member_number' => Member::nextNumber(),
                'phone' => $profile['phone'] ?? null,
                'company' => $profile['company'] ?? null,
                'position' => $profile['position'] ?? null,
                'city' => $profile['city'] ?? null,
                'communication_pref' => ['email' => true, 'whatsapp' => true],
                'status' => 'registered',
            ]);

            // Pendaftaran tamu dengan email yang sama → dikaitkan ke member baru
            Registration::query()->whereNull('user_id')->where('email', $user->email)
                ->update(['user_id' => $user->id, 'member_id' => $member->id]);

            AuditLog::record('member.created', $member, null, ['member_number' => $member->member_number], $user);

            return $member;
        });
    }

    /**
     * Sinkronkan status keanggotaan (PRD §5.6): registered → active (ada kelas berjalan) → alumni (pernah lulus).
     * `inactive` ditetapkan admin dan hanya berubah bila ada aktivitas baru. Peran akun student↔alumni ikut menyesuaikan.
     */
    public function syncStatus(Member $member): Member
    {
        $enrollments = Enrollment::query()->where('member_id', $member->id)->get(['status', 'completed_at']);
        $hasActive = $enrollments->whereIn('status', ['enrolled', 'attending'])->isNotEmpty();
        $completed = $enrollments->where('status', 'completed');
        $status = $hasActive ? 'active' : ($completed->isNotEmpty() ? 'alumni' : ($member->status === 'inactive' ? 'inactive' : 'registered'));
        $attrs = ['status' => $status];
        if ($completed->isNotEmpty() && ! $member->alumni_since) {
            $attrs['alumni_since'] = ($completed->min('completed_at') ?? now())->toDateString();
        }
        if ($status !== $member->status || isset($attrs['alumni_since'])) {
            $before = ['status' => $member->status];
            $member->update($attrs);
            AuditLog::record('member.status_changed', $member, $before, $attrs);
        }
        $user = $member->user;
        if ($user && in_array($user->role, [User::ROLE_STUDENT, User::ROLE_ALUMNI], true)) {
            $role = $status === 'alumni' ? User::ROLE_ALUMNI : User::ROLE_STUDENT;
            if ($user->role !== $role) {
                $user->update(['role' => $role]);
            }
        }

        return $member;
    }
}
