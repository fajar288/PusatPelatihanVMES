<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Pengelolaan akun login oleh admin: ubah nama/peran, nonaktifkan/aktifkan, kirim ulang verifikasi, kirim tautan reset sandi.
 * Admin tidak pernah melihat atau mengetik sandi pengguna. Setiap perubahan peran/status dicatat di audit log.
 *
 * Pengaman:
 * - tidak bisa mengubah peran atau menonaktifkan akun sendiri;
 * - akun dan peran super admin hanya bisa diubah oleh super admin;
 * - harus tersisa minimal satu super admin aktif, dan minimal satu admin pengelola akun aktif (super admin/administrator).
 */
class UserAccountService
{
    /** Peran yang bisa mengelola akun (dipakai pengaman "admin terakhir"). */
    public const MANAGER_ROLES = [User::ROLE_SUPER_ADMIN, User::ROLE_ADMINISTRATOR];

    /**
     * Ubah nama, peran utama, dan peran tambahan.
     *
     * @param  string[]  $extraRoles  slug peran tambahan (tabel user_roles), tanpa peran utama
     */
    public function update(User $actor, User $target, string $name, string $role, array $extraRoles = []): User
    {
        if (! in_array($role, User::ROLES, true) || array_diff($extraRoles, User::ROLES)) {
            throw new DomainException('Peran tidak dikenal.');
        }
        $extraRoles = array_values(array_unique(array_diff($extraRoles, [$role])));
        $before = $this->roleSet($target);
        $after = array_values(array_unique([$role, ...$extraRoles]));
        sort($after);
        $rolesChanged = $before !== $after || $target->role !== $role;

        if ($rolesChanged) {
            if ($actor->is($target)) {
                throw new DomainException('Anda tidak bisa mengubah peran akun sendiri.');
            }
            $this->guardSuperAdminScope($actor, $target, $before, $after);
            $this->guardLastAdmins($target, $after, $target->isActive());
        }

        return DB::transaction(function () use ($actor, $target, $name, $role, $before, $after, $rolesChanged): User {
            $nameBefore = $target->name;
            $primaryBefore = $target->role;
            $target->forceFill(['name' => $name, 'role' => $role])->save();
            if ($rolesChanged) {
                $ids = Role::query()->whereIn('slug', $after)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['created_at' => now()]])->all();
                $target->roles()->sync($ids);
                $target->unsetRelation('roles');
                AuditLog::record('user.roles_changed', $target, ['primary' => $primaryBefore, 'roles' => $before], ['primary' => $role, 'roles' => $after], $actor);
            }
            if ($nameBefore !== $name) {
                AuditLog::record('user.name_changed', $target, ['name' => $nameBefore], ['name' => $name], $actor);
            }

            return $target->refresh();
        });
    }

    /** Nonaktifkan akun: tidak bisa login, semua token API dicabut, sesi panel diakhiri. */
    public function deactivate(User $actor, User $target, ?string $reason = null): User
    {
        if ($actor->is($target)) {
            throw new DomainException('Anda tidak bisa menonaktifkan akun sendiri.');
        }
        if (! $target->isActive()) {
            return $target;
        }
        $roles = $this->roleSet($target);
        $this->guardSuperAdminScope($actor, $target, $roles, $roles);
        $this->guardLastAdmins($target, $roles, false);

        return DB::transaction(function () use ($actor, $target, $reason): User {
            $target->forceFill(['deactivated_at' => now(), 'deactivated_by' => $actor->id])->save();
            $tokens = $target->tokens()->delete();
            DB::table('sessions')->where('user_id', $target->id)->delete();
            AuditLog::record('user.deactivated', $target, ['active' => true], ['active' => false, 'reason' => $reason, 'tokens_revoked' => $tokens], $actor);

            return $target->refresh();
        });
    }

    public function activate(User $actor, User $target): User
    {
        if ($target->isActive()) {
            return $target;
        }
        $roles = $this->roleSet($target);
        $this->guardSuperAdminScope($actor, $target, $roles, $roles);
        $target->forceFill(['deactivated_at' => null, 'deactivated_by' => null])->save();
        AuditLog::record('user.activated', $target, ['active' => false], ['active' => true], $actor);

        return $target->refresh();
    }

    public function resendVerification(User $actor, User $target): void
    {
        if ($target->hasVerifiedEmail()) {
            throw new DomainException('Email akun ini sudah terverifikasi.');
        }
        if (! $target->isActive()) {
            throw new DomainException('Akun nonaktif. Aktifkan dulu sebelum mengirim email.');
        }
        $target->sendEmailVerificationNotification();
        AuditLog::record('user.verification_resent', $target, null, ['email' => $target->email], $actor);
    }

    /** Tautan reset sandi dikirim ke email pengguna (menuju halaman reset di frontend); admin tidak melihat tautannya. */
    public function sendPasswordReset(User $actor, User $target): void
    {
        if (! $target->isActive()) {
            throw new DomainException('Akun nonaktif. Aktifkan dulu sebelum mengirim email.');
        }
        $status = Password::broker()->sendResetLink(['email' => $target->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            throw new DomainException($status === Password::RESET_THROTTLED
                ? 'Tautan reset baru saja dikirim. Coba lagi beberapa saat lagi.'
                : 'Tautan reset sandi gagal dikirim.');
        }
        AuditLog::record('user.password_reset_sent', $target, null, ['email' => $target->email], $actor);
    }

    /** @return string[] semua slug peran akun (utama + user_roles), terurut */
    public function roleSet(User $user): array
    {
        $roles = $user->loadMissing('roles')->roleSlugs();
        sort($roles);

        return $roles;
    }

    /**
     * Pengaman cakupan super admin: hanya super admin yang boleh menyentuh akun super admin
     * atau memberi/mencabut peran super admin.
     *
     * @param  string[]  $before
     * @param  string[]  $after
     */
    private function guardSuperAdminScope(User $actor, User $target, array $before, array $after): void
    {
        $touchesSuper = in_array(User::ROLE_SUPER_ADMIN, $before, true) || in_array(User::ROLE_SUPER_ADMIN, $after, true);
        if ($touchesSuper && ! $actor->isSuperAdmin()) {
            throw new DomainException('Hanya super admin yang bisa mengubah akun atau peran super admin.');
        }
    }

    /**
     * Setelah perubahan harus tersisa minimal satu super admin aktif dan satu pengelola akun aktif.
     *
     * @param  string[]  $rolesAfter
     */
    private function guardLastAdmins(User $target, array $rolesAfter, bool $activeAfter): void
    {
        foreach ([[User::ROLE_SUPER_ADMIN], self::MANAGER_ROLES] as $group) {
            $hadRole = $target->isActive() && count(array_intersect($group, $this->roleSet($target))) > 0;
            $keepsRole = $activeAfter && count(array_intersect($group, $rolesAfter)) > 0;
            if ($hadRole && ! $keepsRole && $this->activeWithAnyRole($group, $target->id) === 0) {
                throw new DomainException($group === [User::ROLE_SUPER_ADMIN]
                    ? 'Ini super admin aktif terakhir. Tetapkan super admin lain lebih dulu.'
                    : 'Ini admin pengelola akun aktif terakhir. Tetapkan admin lain lebih dulu.');
            }
        }
    }

    /** @param  string[]  $roles */
    private function activeWithAnyRole(array $roles, int $exceptId): int
    {
        return User::query()->active()->whereKeyNot($exceptId)
            ->where(fn ($q) => $q->whereIn('role', $roles)->orWhereHas('roles', fn ($r) => $r->whereIn('slug', $roles)))
            ->count();
    }
}
