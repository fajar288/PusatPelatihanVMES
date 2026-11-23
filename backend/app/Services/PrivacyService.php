<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CorporateUser;
use App\Models\DataDeletionRequest;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\ParticipantInvitation;
use App\Models\PushSubscription;
use App\Models\Registration;
use App\Models\RegistrationParticipant;
use App\Models\RequestSeat;
use App\Models\User;
use App\Models\Waitlist;
use App\Support\Notify\Recipient;
use App\Support\PrivateFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /** Pengganti nama untuk data yang dianonimkan. */
    public const ERASED_NAME = 'Data dihapus';

    /**
     * Hapus/anonimkan data pribadi satu akun atas permintaan yang sudah disetujui (DataDeletionService::approve).
     *
     * Urutan: (1) satu transaksi menganonimkan profil, pendaftaran, enrollment, sertifikat (nama disamarkan + sidik),
     * keterikatan perusahaan, sesi & token, dan log notifikasi; email akun BELUM diubah. (2) Berkas pribadi dihapus
     * setelah commit. (3) Email terakhir "penghapusan selesai" dikirim ke alamat asli. (4) Email akun dianonimkan,
     * jejak alamat di log pengiriman dibersihkan, dan permintaan ditandai selesai.
     * Invoice, transaksi, dan bukti bayar tidak dihapus: hanya email penagihan yang dihapus.
     *
     * @return array<string, int> jumlah data per jenis
     */
    public function eraseAccount(User $user, DataDeletionRequest $request, ?User $actor = null): array
    {
        $email = (string) $user->email;
        $name = (string) $user->name;
        $member = $user->member;
        $phone = $member?->phone;
        $needles = array_values(array_filter([$name, $email, $phone], fn ($v) => is_string($v) && mb_strlen(trim($v)) >= 3));
        $files = [];   // [disk, path] dihapus setelah transaksi berhasil
        $counts = [];

        DB::transaction(function () use ($user, $member, $email, $needles, $actor, $request, &$files, &$counts) {
            $memberId = $member?->id ?? 0;
            $erased = self::ERASED_NAME;

            // --- pendaftaran milik akun: identitas pendaftar dihapus, status & nominal tetap untuk statistik
            $registrations = Registration::withTrashed()->ownedBy($user)->get();
            foreach ($registrations as $r) {
                foreach ($r->documentList() as $doc) {
                    $files[] = [Registration::DOCUMENT_DISK, $doc['path']];
                }
                $r->forceFill([
                    'name' => $erased, 'email' => 'dihapus+reg'.$r->id.'@invalid.local', 'phone' => '-',
                    'company' => null, 'position' => null, 'notes' => null, 'status_note' => null,
                    'documents' => null, 'document_names' => null,
                ])->saveQuietly();
            }
            $counts['registrations'] = $registrations->count();
            $counts['registration_participants'] = RegistrationParticipant::query()
                ->where(fn ($q) => $q->where('member_id', $memberId)->orWhere('email', $email))
                ->update(['name' => $erased, 'email' => null, 'phone' => null, 'position' => null, 'updated_at' => now()]);

            // --- invoice tetap (kewajiban akuntansi); hanya email penagihan yang dilepas
            Invoice::query()->whereIn('registration_id', $registrations->modelKeys())->where('bill_to_email', $email)
                ->update(['bill_to_email' => null, 'updated_at' => now()]);

            // --- enrollment tetap terhitung tanpa identitas; berkas tugas dihapus, nilai tetap
            $enrollmentIds = Enrollment::query()->ownedBy($user)->pluck('id');
            $counts['enrollments'] = Enrollment::query()->whereIn('id', $enrollmentIds)
                ->update(['participant_name' => $erased, 'participant_email' => null, 'notes' => null, 'updated_at' => now()]);
            AssignmentSubmission::query()->whereIn('enrollment_id', $enrollmentIds)->whereNotNull('file_path')->get()
                ->each(function (AssignmentSubmission $s) use (&$files) {
                    $files[] = [PrivateFiles::locateDisk($s->file_path), $s->file_path];
                    $s->forceFill(['file_path' => null, 'file_name' => null])->saveQuietly();
                });
            RequestSeat::query()->where('member_id', $memberId)->update(['participant_name' => $erased, 'participant_email' => null, 'updated_at' => now()]);
            ParticipantInvitation::query()->where(fn ($q) => $q->where('accepted_user_id', $user->id)->orWhere('email', $email))->get()
                ->each(fn (ParticipantInvitation $i) => $i->forceFill([
                    'email' => 'dihapus+inv'.$i->id.'@invalid.local', 'name' => null, 'phone' => null, 'employee_id' => null, 'position' => null,
                ])->saveQuietly());
            $counts['waitlists'] = Waitlist::query()->where(fn ($q) => $q->where('member_id', $memberId)->orWhere('email', $email))->delete();

            // --- sertifikat terbit tetap sah; nama disamarkan (sidik untuk pencocokan), PDF di server dihapus
            $certificates = Certificate::query()->ownedBy($user)->whereNull('recipient_anonymized_at')->get();
            foreach ($certificates as $c) {
                if ($c->pdf_path) {
                    $files[] = [config('filesystems.default'), $c->pdf_path];
                }
                $c->forceFill([
                    'recipient_name_hash' => Certificate::nameHash((string) $c->recipient_name),
                    'recipient_name' => $erased, 'recipient_email' => null, 'recipient_anonymized_at' => now(),
                    'pdf_path' => null, 'pdf_generated_at' => null,
                ])->saveQuietly();
            }
            $counts['certificates'] = $certificates->count();

            // --- keterikatan perusahaan diakhiri; akses portal perusahaan dicabut
            EmploymentRelationship::query()->where('member_id', $memberId)->get()->each(fn (EmploymentRelationship $e) => $e->forceFill(
                $e->status === 'active'
                    ? ['status' => 'inactive', 'end_date' => today(), 'end_reason' => 'other', 'employee_id' => null, 'position' => null]
                    : ['employee_id' => null, 'position' => null]
            )->saveQuietly());
            CorporateUser::query()->where('user_id', $user->id)->where('status', 'active')
                ->update(['status' => 'disabled', 'disabled_at' => now(), 'disabled_reason' => 'Penghapusan data atas permintaan pemilik akun', 'updated_at' => now()]);

            // --- profil member: identitas dihapus, nomor member & status tetap (statistik alumni)
            if ($member) {
                if ($member->photo) {
                    $files[] = [MemberService::PHOTO_DISK, $member->photo];
                }
                $member->forceFill([
                    'phone' => null, 'birth_date' => null, 'gender' => null, 'id_number' => null, 'company' => null, 'position' => null,
                    'education' => null, 'address' => null, 'city' => null, 'photo' => null, 'communication_pref' => null,
                ])->saveQuietly();
            }

            // --- akun: nama dihapus, login dimatikan, semua sesi & token dicabut (email diubah setelah email terakhir)
            $user->forceFill([
                'name' => $erased, 'password' => Hash::make(Str::random(64)), 'remember_token' => null, 'email_verified_at' => null,
                'deactivated_at' => now(), 'deactivated_by' => $actor?->id,
            ])->saveQuietly();
            $counts['tokens'] = $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            PushSubscription::query()->where('user_id', $user->id)->delete();
            NotificationPreference::query()->where('user_id', $user->id)->delete();
            $user->notifications()->delete();
            DB::table('ai_recommendation_events')->where('user_id', $user->id)->orWhere('member_id', $memberId)->delete();

            // --- log notifikasi: antrean lain dibatalkan, alamat & nama dihapus dari pesan yang sudah terkirim. Pesan
            // tentang permintaan ini sendiri (mis. "disetujui") yang masih antre tetap dikirim ke alamat asli.
            $thisRequest = fn ($q) => $q->where('subject_type', $request->getMorphClass())->where('subject_id', $request->id);
            NotificationDelivery::query()->where('user_id', $user->id)->where('status', 'queued')->whereDoesntHave('event', $thisRequest)
                ->update(['status' => 'cancelled', 'last_error' => 'Dibatalkan: penghapusan data pemilik akun', 'updated_at' => now()]);
            $this->scrubDeliveries(NotificationDelivery::query()->where('user_id', $user->id)->where('status', '!=', 'queued'), $needles);
            $this->scrubDeliveries(NotificationDelivery::query()->where('status', '!=', 'queued')
                ->where(fn ($q) => $this->whereContains($q, ['content', 'recipient', 'recipient_name'], $needles)), $needles);
            NotificationLog::query()->where('user_id', $user->id)->orWhere('recipient', $email)->update(['recipient' => null]);
            NotificationEvent::query()->where(fn ($q) => $this->whereContains($q, ['payload'], $needles))->get()
                ->each(fn (NotificationEvent $ev) => $ev->forceFill(['payload' => $this->scrub($ev->payload, $needles)])->saveQuietly());
            AuditLog::query()->where('actor_id', $user->id)->update(['actor_name' => $erased]);
        });

        foreach ($files as [$disk, $path]) {
            if ($disk && $path) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
        $counts['files'] = count($files);

        // Email terakhir ke alamat asli, sebelum email akun dianonimkan.
        $final = null;
        try {
            $final = app(NotificationCenter::class)->publish('privacy.deletion_completed',
                [new Recipient(Recipient::PARTICIPANT, null, $name, $email)], ['name' => $name],
                ['subject' => $request, 'actor' => $actor, 'only_channels' => ['mail'], 'dedupe' => 'privacy.deletion_completed:'.$request->id]);
        } catch (\Throwable $e) {
            report($e);
        }

        DB::transaction(function () use ($user, $request, $actor, $counts, $final, $needles) {
            $user->forceFill(['email' => 'dihapus+'.$user->id.'@invalid.local'])->saveQuietly();
            // pesan terakhir: alamat tetap disimpan hanya selama masih menunggu dikirim ulang
            $finalIds = $final && $final['event'] ? $final['event']->deliveries()->where('status', '!=', 'queued')->pluck('id') : collect();
            $this->scrubDeliveries(NotificationDelivery::query()->whereIn('id', $finalIds), $needles, 'participant:dihapus#'.$request->id);
            $request->update(['status' => 'completed', 'completed_at' => now()]);
            AuditLog::record('privacy.account_erased', $request, null, $counts, $actor);
        });

        return $counts;
    }

    /**
     * Baris yang kolomnya memuat salah satu teks (tanpa membedakan huruf besar/kecil). Kolom JSON di-cast ke teks agar
     * berlaku di PostgreSQL (json tidak mendukung LIKE), MySQL/MariaDB, dan SQLite. Tanpa escape: % atau _ di teks hanya
     * memperluas kandidat, penggantian sebenarnya dilakukan persis di PHP (scrub).
     *
     * @param  list<string>  $needles
     */
    private function whereContains($q, array $columns, array $needles): void
    {
        $q->whereRaw('1 = 0');
        $text = DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb' ? 'CHAR' : 'TEXT';
        foreach ($columns as $col) {
            foreach ($needles as $n) {
                $q->orWhereRaw("LOWER(CAST($col AS $text)) LIKE ?", ['%'.mb_strtolower($n).'%']);
            }
        }
    }

    /** @param list<string> $needles */
    private function scrubDeliveries($query, array $needles, ?string $recipientKey = null): void
    {
        $query->get()->each(function (NotificationDelivery $d) use ($needles, $recipientKey) {
            $d->forceFill(array_filter([
                'recipient' => $d->recipient === null ? null : $this->scrub($d->recipient, $needles),
                'recipient_name' => $d->recipient_name === null ? null : $this->scrub($d->recipient_name, $needles),
                'content' => $this->scrub($d->content, $needles),
                'recipient_key' => $recipientKey,
            ], fn ($v, $k) => $k !== 'recipient_key' || $v !== null, ARRAY_FILTER_USE_BOTH))->saveQuietly();
        });
    }

    /** Ganti nama/email/telepon di teks atau array (rekursif) dengan penanda data dihapus. @param list<string> $needles */
    private function scrub(mixed $value, array $needles): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->scrub($v, $needles), $value);
        }
        if (! is_string($value) || $needles === []) {
            return $value;
        }

        return str_ireplace($needles, self::ERASED_NAME, $value);
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
