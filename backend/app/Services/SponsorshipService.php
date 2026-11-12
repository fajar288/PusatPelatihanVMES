<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\SponsorshipTransfer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfer sponsorship sebagai workflow resmi (PRD Roadmap 5 §20.20, R7, R8, R10):
 * permintaan → otorisasi pihak berwenang (izin sponsorship.approve, bukan peminta) → berlaku per tanggal efektif →
 * catatan sponsor lama/baru, tanggal & alasan → audit yang tidak bisa diubah. Histori tidak pernah diubah diam-diam.
 */
class SponsorshipService
{
    public function request(Enrollment $enrollment, ?Organization $to, mixed $effectiveDate, string $reason, ?string $authorizationReference = null, ?OrganizationUnit $toUnit = null, ?User $actor = null): SponsorshipTransfer
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan transfer wajib diisi.']);
        }
        if (($to?->id) === $enrollment->sponsor_organization_id && ($toUnit?->id) === $enrollment->sponsor_unit_id) {
            throw ValidationException::withMessages(['to_organization_id' => 'Sponsor tujuan sama dengan sponsor saat ini.']);
        }
        if ($toUnit && (! $to || $toUnit->organization_id !== $to->id)) {
            throw ValidationException::withMessages(['to_unit_id' => 'Unit bukan milik organisasi tujuan.']);
        }
        if ($to && ! $to->isActive()) {
            throw ValidationException::withMessages(['to_organization_id' => 'Organisasi tujuan tidak aktif.']);
        }
        if (SponsorshipTransfer::query()->where('enrollment_id', $enrollment->id)->whereIn('status', ['requested', 'approved'])->exists()) {
            throw ValidationException::withMessages(['enrollment_id' => 'Masih ada transfer sponsor yang belum selesai untuk enrollment ini.']);
        }

        return DB::transaction(function () use ($enrollment, $to, $toUnit, $effectiveDate, $reason, $authorizationReference, $actor) {
            $t = SponsorshipTransfer::query()->create([
                'code' => SponsorshipTransfer::nextCode(),
                'enrollment_id' => $enrollment->id,
                'from_organization_id' => $enrollment->sponsor_organization_id,
                'to_organization_id' => $to?->id,
                'to_unit_id' => $toUnit?->id,
                'effective_date' => Carbon::parse($effectiveDate)->toDateString(),
                'reason' => trim($reason),
                'authorization_reference' => $authorizationReference,
                'status' => 'requested',
                'requested_by' => $actor?->id,
            ]);
            AuditLog::record('sponsorship_transfer.requested', $t, null, $t->only(['code', 'enrollment_id', 'from_organization_id', 'to_organization_id', 'to_unit_id', 'effective_date', 'reason', 'authorization_reference']), $actor);

            return $t;
        });
    }

    /** Otorisasi oleh pihak berwenang (bukan peminta). Bila tanggal efektif sudah tiba → langsung diterapkan. */
    public function approve(SponsorshipTransfer $t, User $actor, ?string $note = null): SponsorshipTransfer
    {
        $this->assertStatus($t, ['requested']);
        if (! ($actor->hasPermission('sponsorship.approve') || $actor->isSuperAdmin())) {
            throw ValidationException::withMessages(['actor' => 'Anda tidak berwenang menyetujui transfer sponsor.']);
        }
        if ($t->requested_by !== null && $t->requested_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => 'Transfer harus disetujui oleh orang lain selain peminta.']);
        }
        $t->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
        AuditLog::record('sponsorship_transfer.approved', $t, ['status' => 'requested'], ['status' => 'approved', 'note' => $note], $actor);

        return $t->effective_date->isAfter(today()) ? $t : $this->apply($t, $actor);
    }

    public function reject(SponsorshipTransfer $t, User $actor, string $note): SponsorshipTransfer
    {
        $this->assertStatus($t, ['requested']);
        $t->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
        AuditLog::record('sponsorship_transfer.rejected', $t, ['status' => 'requested'], ['status' => 'rejected', 'note' => $note], $actor);

        return $t;
    }

    public function cancel(SponsorshipTransfer $t, User $actor, string $note): SponsorshipTransfer
    {
        $this->assertStatus($t, ['requested', 'approved']);
        $before = $t->status;
        $t->update(['status' => 'cancelled', 'decision_note' => $note, 'decided_by' => $t->decided_by ?? $actor->id, 'decided_at' => $t->decided_at ?? now()]);
        AuditLog::record('sponsorship_transfer.cancelled', $t, ['status' => $before], ['status' => 'cancelled', 'note' => $note], $actor);

        return $t;
    }

    /** Terapkan transfer yang disetujui & sudah efektif: ubah sponsor enrollment + audit sponsor lama → baru. */
    public function apply(SponsorshipTransfer $t, ?User $actor = null): SponsorshipTransfer
    {
        $this->assertStatus($t, ['approved']);
        if ($t->effective_date->isAfter(today())) {
            throw ValidationException::withMessages(['effective_date' => 'Tanggal efektif belum tiba.']);
        }

        return DB::transaction(function () use ($t, $actor) {
            $e = Enrollment::query()->lockForUpdate()->findOrFail($t->enrollment_id);
            if ($e->sponsor_organization_id !== $t->from_organization_id) {
                throw ValidationException::withMessages(['enrollment_id' => 'Sponsor enrollment sudah berubah sejak permintaan dibuat.']);
            }
            $before = $e->only(['type', 'sponsor_organization_id', 'sponsor_unit_id']);
            Enrollment::$sponsorChangeAuthorized = true;
            try {
                $e->update([
                    'type' => $t->to_organization_id ? Enrollment::TYPE_CORPORATE : Enrollment::TYPE_SELF,
                    'sponsor_organization_id' => $t->to_organization_id,
                    'sponsor_unit_id' => $t->to_unit_id,
                ]);
            } finally {
                Enrollment::$sponsorChangeAuthorized = false;
            }
            $t->update(['status' => 'applied', 'applied_at' => now()]);
            AuditLog::record('enrollment.sponsor_transferred', $e, $before + ['transfer' => $t->code], $e->only(['type', 'sponsor_organization_id', 'sponsor_unit_id']) + [
                'transfer' => $t->code, 'effective_date' => $t->effective_date->toDateString(), 'reason' => $t->reason, 'authorization_reference' => $t->authorization_reference,
            ], $actor);

            return $t;
        });
    }

    /** Dijalankan terjadwal: terapkan transfer yang disetujui dan tanggal efektifnya sudah tiba. */
    public function applyDue(): int
    {
        $n = 0;
        SponsorshipTransfer::query()->due()->orderBy('effective_date')->each(function (SponsorshipTransfer $t) use (&$n): void {
            $this->apply($t);
            $n++;
        });

        return $n;
    }

    private function assertStatus(SponsorshipTransfer $t, array $allowed): void
    {
        if (! in_array($t->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Transfer berstatus "'.(SponsorshipTransfer::STATUSES[$t->status] ?? $t->status).'" — aksi tidak diizinkan.']);
        }
    }
}
