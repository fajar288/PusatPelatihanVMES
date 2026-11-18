<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\NotificationPreference;
use App\Models\Registration;
use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hak subjek data & retensi (PRD non-fungsional: privasi). export(): salinan data pribadi milik akun dalam bentuk
 * yang bisa dibaca mesin. prune(): aturan retensi dari config/privacy.php (semua mati bawaan sampai kebijakan PPTI
 * ditetapkan). Data keuangan dan sertifikat tidak dihapus karena wajib disimpan.
 */
class PrivacyService
{
    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $member = $user->member;
        $registrations = Registration::query()->ownedBy($user)->with(['schedule.package', 'participants'])->orderBy('id')->get();
        $enrollments = Enrollment::query()->ownedBy($user)->with('schedule.package')->orderBy('id')->get();
        $invoices = Invoice::query()->whereIn('registration_id', $registrations->modelKeys())->orderBy('id')->get();
        $certificates = Certificate::query()->ownedBy($user)->orderBy('id')->get();

        AuditLog::record('privacy.data_exported', $user, null, ['registrations' => $registrations->count(), 'enrollments' => $enrollments->count()], $user);

        return [
            'exportedAt' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),
                'createdAt' => $user->created_at?->toIso8601String(),
                'lastLoginAt' => $user->last_login_at?->toIso8601String(),
                'roles' => $user->roleSlugs(),
            ],
            'member' => $member ? [
                'memberNumber' => $member->member_number,
                'status' => $member->status,
                'phone' => $member->phone,
                'birthDate' => $member->birth_date?->toDateString(),
                'gender' => $member->gender,
                'idNumber' => $member->id_number,
                'company' => $member->company,
                'position' => $member->position,
                'education' => $member->education,
                'address' => $member->address,
                'city' => $member->city,
                'hasPhoto' => (bool) $member->photo,
                'communicationPreference' => $member->communication_pref,
                'alumniSince' => $member->alumni_since?->toDateString(),
            ] : null,
            'notificationPreferences' => NotificationPreference::query()->where('user_id', $user->id)->get()
                ->map(fn ($p) => collect($p->getAttributes())->except(['id', 'user_id', 'created_at', 'updated_at'])->all())->values(),
            'registrations' => $registrations->map(fn (Registration $r) => [
                'number' => $r->registration_number,
                'status' => $r->status->value,
                'type' => $r->type,
                'program' => $r->schedule?->package?->title,
                'classCode' => $r->schedule?->code,
                'name' => $r->name,
                'email' => $r->email,
                'phone' => $r->phone,
                'company' => $r->company,
                'position' => $r->position,
                'notes' => $r->notes,
                'consent' => (bool) $r->consent,
                'documents' => array_column($r->documentList(), 'name'),
                'participants' => $r->participants->map(fn ($p) => ['name' => $p->name, 'email' => $p->email, 'phone' => $p->phone, 'position' => $p->position])->values(),
                'createdAt' => $r->created_at?->toIso8601String(),
            ])->values(),
            'enrollments' => $enrollments->map(fn (Enrollment $e) => [
                'program' => $e->schedule?->package?->title,
                'classCode' => $e->schedule?->code,
                'status' => $e->status,
                'type' => $e->type,
                'enrolledAt' => $e->enrolled_at?->toIso8601String(),
                'completedAt' => $e->completed_at?->toIso8601String(),
                'finalScore' => $e->final_score,
            ])->values(),
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'number' => $i->invoice_number,
                'status' => $i->status,
                'total' => (float) $i->total,
                'amountPaid' => (float) $i->amount_paid,
                'billTo' => ['name' => $i->bill_to_name, 'email' => $i->bill_to_email, 'company' => $i->bill_to_company],
                'createdAt' => $i->created_at?->toIso8601String(),
            ])->values(),
            'certificates' => $certificates->map(fn (Certificate $c) => [
                'number' => $c->number,
                'type' => $c->type,
                'status' => $c->status,
                'program' => $c->program_title,
                'recipientName' => $c->recipient_name,
                'issuedAt' => $c->issued_at?->toIso8601String(),
                'validUntil' => $c->valid_until?->toDateString(),
            ])->values(),
        ];
    }

    /**
     * Jalankan aturan retensi yang diaktifkan. Mengembalikan jumlah data per aturan (null = aturan mati).
     *
     * @return array<string, int|null>
     */
    public function prune(bool $dryRun = false): array
    {
        return [
            'registration_documents' => $this->pruneRegistrationDocuments($this->days('registration_documents_days'), $dryRun),
            'closed_registrations' => $this->anonymizeClosedRegistrations($this->days('closed_registration_days'), $dryRun),
            'waitlists' => $this->pruneWaitlists($this->days('waitlist_days'), $dryRun),
        ];
    }

    private function days(string $key): ?int
    {
        $v = config('privacy.'.$key);

        return $v === null || $v === '' ? null : max(1, (int) $v);
    }

    private function pruneRegistrationDocuments(?int $days, bool $dryRun): ?int
    {
        if ($days === null) {
            return null;
        }
        $final = [RegistrationStatus::Confirmed->value, RegistrationStatus::Rejected->value, RegistrationStatus::Cancelled->value];
        $q = Registration::query()->whereIn('status', $final)->whereNotNull('documents')->where('updated_at', '<', now()->subDays($days));
        if ($dryRun) {
            return $q->get()->filter(fn (Registration $r) => $r->documentList() !== [])->count();
        }
        $n = 0;
        $q->each(function (Registration $r) use (&$n) {
            $docs = $r->documentList();
            if ($docs === []) {
                return;
            }
            Storage::disk(Registration::DOCUMENT_DISK)->delete(array_column($docs, 'path'));
            // tanpa menyentuh updated_at agar jam retensi aturan lain tidak ikut mundur
            $r->timestamps = false;
            $r->forceFill(['documents' => null, 'document_names' => null])->saveQuietly();
            AuditLog::record('privacy.documents_pruned', $r, null, ['documents' => count($docs)], null);
            $n++;
        });

        return $n;
    }

    private function anonymizeClosedRegistrations(?int $days, bool $dryRun): ?int
    {
        if ($days === null) {
            return null;
        }
        $q = Registration::query()
            ->whereIn('status', [RegistrationStatus::Rejected->value, RegistrationStatus::Cancelled->value])
            ->whereDoesntHave('invoices')
            ->where('updated_at', '<', now()->subDays($days))
            ->where('name', '!=', 'Data dihapus');
        if ($dryRun) {
            return $q->count();
        }
        $n = 0;
        $q->each(function (Registration $r) use (&$n) {
            DB::transaction(function () use ($r) {
                Storage::disk(Registration::DOCUMENT_DISK)->delete(array_column($r->documentList(), 'path'));
                $r->participants()->delete();
                // nomor registrasi, jadwal, status, dan jumlah peserta tetap untuk statistik; identitas dihapus
                $r->forceFill([
                    'name' => 'Data dihapus', 'email' => 'dihapus+'.$r->id.'@invalid.local', 'phone' => '-',
                    'company' => null, 'position' => null, 'notes' => null, 'status_note' => null,
                    'documents' => null, 'document_names' => null, 'user_id' => null, 'member_id' => null,
                ])->saveQuietly();
                AuditLog::record('privacy.registration_anonymized', $r, null, null, null);
            });
            $n++;
        });

        return $n;
    }

    private function pruneWaitlists(?int $days, bool $dryRun): ?int
    {
        if ($days === null) {
            return null;
        }
        $q = Waitlist::query()->whereHas('schedule', fn ($s) => $s->whereDate('end_date', '<', now()->subDays($days)));

        return $dryRun ? $q->count() : $q->delete();
    }
}
