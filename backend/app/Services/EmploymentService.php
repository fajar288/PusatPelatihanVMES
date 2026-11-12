<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Siklus hubungan kerja (PRD Roadmap 5 §4.5, §20.6–§20.8). Resign/pindah tidak menghapus Member, enrollment,
 * maupun sertifikat (R4, R9) — hanya employment menjadi INACTIVE dan akses corporate orang itu di organisasi tsb dicabut.
 */
class EmploymentService
{
    public function __construct(private OrganizationService $organizations) {}

    /** @param array{unit_id?:?int, employee_id?:?string, position?:?string, start_date?:mixed} $data */
    public function start(Member $member, Organization $org, array $data = [], string $source = 'admin', ?User $actor = null): EmploymentRelationship
    {
        return DB::transaction(function () use ($member, $org, $data, $source, $actor) {
            if (EmploymentRelationship::query()->active()->where('member_id', $member->id)->where('organization_id', $org->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['member_id' => 'Member ini sudah memiliki hubungan kerja aktif di organisasi ini.']);
            }
            $employeeId = trim((string) ($data['employee_id'] ?? '')) ?: null;
            if ($employeeId && EmploymentRelationship::query()->active()->where('organization_id', $org->id)->where('employee_id', $employeeId)->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'Employee ID ini sudah dipakai karyawan aktif lain di organisasi ini.']);
            }
            if (! empty($data['unit_id']) && OrganizationUnit::query()->whereKey($data['unit_id'])->value('organization_id') !== $org->id) {
                throw ValidationException::withMessages(['unit_id' => 'Unit bukan milik organisasi ini.']);
            }
            $e = EmploymentRelationship::query()->create([
                'code' => EmploymentRelationship::nextCode(),
                'member_id' => $member->id,
                'organization_id' => $org->id,
                'unit_id' => $data['unit_id'] ?? null,
                'employee_id' => $employeeId,
                'position' => $data['position'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'status' => 'active',
                'source' => $source,
            ]);
            AuditLog::record('employment.started', $e, null, $e->only(['code', 'member_id', 'organization_id', 'unit_id', 'employee_id', 'position', 'source']), $actor);

            return $e;
        });
    }

    /** Resign / kontrak berakhir / pindah: employment → INACTIVE, akses corporate orang ini di organisasi tsb dicabut. */
    public function end(EmploymentRelationship $e, mixed $endDate = null, string $reason = 'resigned', ?User $actor = null): EmploymentRelationship
    {
        if (! array_key_exists($reason, EmploymentRelationship::END_REASONS)) {
            throw ValidationException::withMessages(['end_reason' => 'Alasan berakhir tidak dikenal.']);
        }
        if (! $e->isActive()) {
            throw ValidationException::withMessages(['status' => 'Hubungan kerja ini sudah tidak aktif.']);
        }

        return DB::transaction(function () use ($e, $endDate, $reason, $actor) {
            $date = $endDate ? Carbon::parse($endDate)->toDateString() : today()->toDateString();
            $e->update(['status' => 'inactive', 'end_date' => $date, 'end_reason' => $reason]);
            AuditLog::record('employment.ended', $e, ['status' => 'active'], ['status' => 'inactive', 'end_date' => $date, 'end_reason' => $reason], $actor);

            $userId = $e->member?->user_id;
            if ($userId) {
                CorporateUser::query()->where('organization_id', $e->organization_id)->where('user_id', $userId)->active()->get()
                    ->each(fn (CorporateUser $cu) => $this->organizations->disableCorporateUser($cu, 'employment_ended', $actor));
            }

            return $e;
        });
    }

    /** Pindah perusahaan (§20.8): akhiri employment lama (transferred) lalu mulai employment baru. Histori sponsor tidak ikut. */
    public function move(EmploymentRelationship $from, Organization $to, array $data = [], ?User $actor = null): EmploymentRelationship
    {
        return DB::transaction(function () use ($from, $to, $data, $actor) {
            $this->end($from, $data['start_date'] ?? null, 'transferred', $actor);

            return $this->start($from->member, $to, $data, $data['source'] ?? 'admin', $actor);
        });
    }
}
