<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CorporateUser;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Registration;
use App\Models\User;
use App\Support\Enterprise\OrganizationName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Akun perusahaan & pengguna perusahaan (PRD Roadmap 5 §4.1, §20.12, §20.14).
 * Setiap perubahan akses (tambah/ubah peran/batas unit/nonaktif) diaudit (R10, §20.21).
 */
class OrganizationService
{
    /** @param array<string,mixed> $data */
    public function create(array $data, ?User $actor = null): Organization
    {
        return DB::transaction(function () use ($data, $actor) {
            $org = Organization::query()->create($data + ['code' => Organization::nextCode(), 'status' => 'active', 'source' => 'admin']);
            AuditLog::record('organization.created', $org, null, $org->only(['code', 'legal_name', 'source', 'needs_review']), $actor);

            return $org;
        });
    }

    /**
     * Organisasi sponsor untuk pendaftaran kolektif yang belum ditautkan admin: cari berdasarkan nama ternormalisasi,
     * bila tidak ada → buat baru dengan needs_review agar diperiksa admin (kolom company hanya isian, bukan hak akses).
     */
    public function forRegistration(Registration $registration, ?User $actor = null): Organization
    {
        if ($registration->organization) {
            return $registration->organization;
        }
        $legal = trim((string) $registration->company) !== '' ? trim((string) $registration->company) : "Perusahaan tanpa nama ({$registration->registration_number})";
        $norm = OrganizationName::normalize($legal);
        $org = ($norm !== '' ? Organization::query()->where('normalized_name', $norm)->orderBy('id')->first() : null)
            ?? $this->create(['legal_name' => $legal, 'needs_review' => true, 'source' => 'registration', 'contact_name' => $registration->name, 'contact_email' => $registration->email, 'contact_phone' => $registration->phone], $actor);
        $registration->update(['organization_id' => $org->id]);
        AuditLog::record('registration.organization_linked', $registration, null, ['organization_id' => $org->id, 'auto' => true], $actor);

        return $org;
    }

    public function addCorporateUser(Organization $org, User $user, string $role, ?OrganizationUnit $unit = null, ?User $actor = null): CorporateUser
    {
        $this->assertRole($role);
        $this->assertUnit($org, $unit);

        return DB::transaction(function () use ($org, $user, $role, $unit, $actor) {
            $cu = CorporateUser::query()->firstOrNew(['organization_id' => $org->id, 'user_id' => $user->id]);
            $before = $cu->exists ? $cu->only(['role', 'unit_id', 'status']) : null;
            $cu->fill(['role' => $role, 'unit_id' => $unit?->id, 'status' => 'active', 'disabled_at' => null, 'disabled_reason' => null, 'invited_by' => $cu->invited_by ?? $actor?->id])->save();
            AuditLog::record($before ? 'corporate_user.reactivated' : 'corporate_user.added', $cu, $before, $cu->only(['organization_id', 'user_id', 'role', 'unit_id', 'status']), $actor);

            return $cu;
        });
    }

    /** Ganti peran / batas unit — tidak menyentuh data peserta (§11 "Corporate admin berganti"). */
    public function changeRole(CorporateUser $cu, string $role, ?OrganizationUnit $unit = null, ?User $actor = null): CorporateUser
    {
        $this->assertRole($role);
        $this->assertUnit($cu->organization, $unit);
        $before = $cu->only(['role', 'unit_id']);
        $cu->update(['role' => $role, 'unit_id' => $unit?->id]);
        AuditLog::record('corporate_user.role_changed', $cu, $before, $cu->only(['role', 'unit_id']), $actor);

        return $cu;
    }

    /** Cabut akses (berlaku seketika: setiap request corporate memeriksa status). */
    public function disableCorporateUser(CorporateUser $cu, string $reason, ?User $actor = null): CorporateUser
    {
        if (! $cu->isActive()) {
            return $cu;
        }
        $cu->update(['status' => 'disabled', 'disabled_at' => now(), 'disabled_reason' => $reason]);
        AuditLog::record('corporate_user.disabled', $cu, ['status' => 'active'], ['status' => 'disabled', 'reason' => $reason], $actor);

        return $cu;
    }

    public function setStatus(Organization $org, string $status, ?User $actor = null): Organization
    {
        if (! array_key_exists($status, Organization::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Status organisasi tidak dikenal.']);
        }
        $before = $org->status;
        $org->update(['status' => $status]);
        AuditLog::record('organization.status_changed', $org, ['status' => $before], ['status' => $status], $actor);

        return $org;
    }

    private function assertRole(string $role): void
    {
        if (! array_key_exists($role, CorporateUser::ROLES)) {
            throw ValidationException::withMessages(['role' => 'Peran perusahaan tidak dikenal.']);
        }
    }

    private function assertUnit(Organization $org, ?OrganizationUnit $unit): void
    {
        if ($unit && $unit->organization_id !== $org->id) {
            throw ValidationException::withMessages(['unit_id' => 'Unit bukan milik organisasi ini.']);
        }
    }
}
