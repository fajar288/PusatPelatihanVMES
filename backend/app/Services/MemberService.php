<?php

namespace App\Services;

use App\Models\AuditLog;
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
            $member = $user->member ?? Member::query()->create([
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
}
