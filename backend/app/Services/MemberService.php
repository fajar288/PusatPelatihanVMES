<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Registration;
use App\Models\User;
use App\Support\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Satu akun = satu member (PRD §5.6). */
class MemberService
{
    /** Foto profil member: data pribadi, disimpan di disk privat dan hanya dibuka pemilik akun atau admin. */
    public const PHOTO_DISK = 'local';

    /** Sisi foto profil hasil kompresi (persegi, WebP). */
    public const PHOTO_SIZE = 400;

    /**
     * Simpan foto profil baru (dipotong persegi & dikompresi ke WebP bila GD tersedia), hapus foto lama, catat audit.
     */
    public function updatePhoto(Member $member, UploadedFile $file, ?User $actor = null): Member
    {
        $disk = Storage::disk(self::PHOTO_DISK);
        $dir = 'members/photos';
        $webp = ImageProcessor::toWebp((string) file_get_contents($file->getRealPath()), self::PHOTO_SIZE, square: true);
        if ($webp !== null) {
            $path = $dir.'/'.$member->member_number.'-'.Str::random(16).'.webp';
            $disk->put($path, $webp);
        } else {
            $path = $file->store($dir, self::PHOTO_DISK) ?: throw new \RuntimeException('Foto gagal disimpan.');
        }

        $old = $member->photo;
        $member->forceFill(['photo' => $path])->save();
        if ($old && $old !== $path && ! str_contains($old, '://')) {
            $disk->delete($old);
        }
        AuditLog::record('member.photo_updated', $member, ['photo' => $old ? 'ada' : null], ['photo' => 'baru'], $actor);

        return $member;
    }

    public function removePhoto(Member $member, ?User $actor = null): Member
    {
        if ($member->photo) {
            if (! str_contains($member->photo, '://')) {
                Storage::disk(self::PHOTO_DISK)->delete($member->photo);
            }
            $member->forceFill(['photo' => null])->save();
            AuditLog::record('member.photo_removed', $member, ['photo' => 'ada'], ['photo' => null], $actor);
        }

        return $member;
    }

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
