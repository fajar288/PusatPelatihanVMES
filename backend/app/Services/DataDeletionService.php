<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\DataDeletionRequests\DataDeletionRequestResource;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CorporateUser;
use App\Models\DataDeletionRequest;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentRefund;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\User;
use App\Support\IdDate;
use App\Support\Notify\Recipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alur permintaan penghapusan data pribadi: peserta mengajukan (konfirmasi email/kata sandi), membatalkan selama
 * belum diproses; admin berizin `privacy.manage` menyetujui (lalu PrivacyService::eraseAccount) atau menolak.
 *
 * Penghalang (blockers) membuat permintaan ditolak otomatis saat diajukan dan mencegah persetujuan: enrollment yang
 * masih berjalan, pendaftaran yang masih diproses, invoice belum lunas, refund yang sedang diproses, atau akun staf.
 * Peringatan (warnings) tidak menghalangi, hanya menjelaskan dampaknya (sertifikat, keterikatan perusahaan).
 */
class DataDeletionService
{
    public function __construct(
        private readonly PrivacyService $privacy,
        private readonly NotificationCenter $center,
        private readonly NotificationRecipients $recipients,
    ) {}

    /** Data yang dihapus/dianonimkan dan yang tetap disimpan beserta alasannya (ditampilkan di portal dan admin). */
    public const POLICY = [
        'deleted' => [
            'Nama, email, nomor telepon, alamat, tanggal lahir, NIK, pendidikan, perusahaan, dan jabatan di profil akun dan member.',
            'Foto profil dan dokumen pendukung pendaftaran (berkasnya dihapus dari server).',
            'Berkas tugas yang Anda unggah di kelas online (nilai tetap tercatat tanpa nama).',
            'Nama dan kontak pada pendaftaran, enrollment, dan daftar tunggu; riwayat pelatihan tetap terhitung dalam statistik tanpa identitas.',
            'Preferensi notifikasi, langganan push, kotak masuk notifikasi, dan riwayat kontak pada log pengiriman.',
            'Akses login: akun dinonaktifkan, kata sandi diganti acak, dan semua sesi dicabut.',
        ],
        'kept' => [
            ['item' => 'Invoice, transaksi pembayaran, dan bukti bayar', 'reason' => 'Wajib disimpan sebagai catatan akuntansi dan perpajakan. Dilepas dari akun; nama penagihan pada invoice tetap seperti saat diterbitkan, email penagihan dihapus.'],
            ['item' => 'Sertifikat yang sudah terbit', 'reason' => 'Tetap sah sebagai bukti kompetensi Anda. Nama tidak lagi tampil di halaman verifikasi publik; pemegang sertifikat atau pemberi kerja masih bisa mencocokkan nama yang tertera di sertifikat. Berkas PDF di server dihapus, PDF yang sudah Anda unduh tetap berlaku.'],
            ['item' => 'Nomor member, nilai, kehadiran, dan status kelulusan tanpa nama', 'reason' => 'Diperlukan agar laporan pelatihan dan laporan perusahaan yang mensponsori tetap konsisten.'],
            ['item' => 'Catatan audit', 'reason' => 'Jejak siapa melakukan apa dan kapan tetap disimpan untuk keamanan dan akuntabilitas.'],
        ],
    ];

    public function deadlineDays(): int
    {
        return max(1, (int) config('privacy.deletion_deadline_days', 14));
    }

    public function latestFor(User $user): ?DataDeletionRequest
    {
        return DataDeletionRequest::query()->where('user_id', $user->id)->latest('id')->first();
    }

    /**
     * Hal yang menghalangi penghapusan. Kode stabil untuk frontend, pesan untuk peserta dan admin.
     *
     * @return list<array{code: string, message: string}>
     */
    public function blockers(User $user): array
    {
        $out = [];
        if ($user->hasRole(...User::ADMIN_ROLES) || Trainer::query()->where('user_id', $user->id)->exists()) {
            $out[] = ['code' => 'staff_account', 'message' => 'Akun ini terdaftar sebagai staf atau trainer. Penghapusan akun staf dilakukan admin lewat menu Pengguna.'];
        }

        $running = Enrollment::query()->ownedBy($user)->whereIn('status', ['enrolled', 'attending'])
            ->whereHas('schedule', fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->with('schedule.package')->get();
        if ($running->isNotEmpty()) {
            $out[] = ['code' => 'enrollment_running', 'message' => 'Masih mengikuti pelatihan yang belum selesai: '.$running->map(fn (Enrollment $e) => $e->schedule?->package?->title ?? $e->schedule?->code)->filter()->unique()->implode(', ').'. Ajukan setelah pelatihan selesai atau hubungi admin untuk mengundurkan diri.'];
        }

        $open = [RegistrationStatus::Pending->value, RegistrationStatus::UnderReview->value, RegistrationStatus::WaitingPayment->value, RegistrationStatus::PaymentVerification->value];
        $registrations = Registration::query()->ownedBy($user)->whereIn('status', $open)->pluck('registration_number');
        if ($registrations->isNotEmpty()) {
            $out[] = ['code' => 'registration_in_progress', 'message' => 'Pendaftaran masih diproses: '.$registrations->implode(', ').'. Batalkan pendaftaran di portal atau tunggu sampai selesai.'];
        }

        $invoiceIds = $this->invoiceIds($user);
        $unpaid = Invoice::query()->whereIn('id', $invoiceIds)->whereIn('status', Invoice::PAYABLE)->pluck('invoice_number');
        if ($unpaid->isNotEmpty()) {
            $out[] = ['code' => 'invoice_unpaid', 'message' => 'Invoice belum lunas: '.$unpaid->implode(', ').'. Selesaikan pembayaran atau minta admin membatalkan invoice.'];
        }

        $refunds = PaymentRefund::query()->whereIn('invoice_id', $invoiceIds)->whereIn('status', ['requested', 'approved'])->count();
        if ($refunds > 0) {
            $out[] = ['code' => 'refund_in_progress', 'message' => 'Ada pengembalian dana yang sedang diproses. Ajukan setelah refund selesai ditransfer.'];
        }

        return $out;
    }

    /**
     * Dampak yang tidak menghalangi tetapi perlu diketahui.
     *
     * @return list<array{code: string, message: string}>
     */
    public function warnings(User $user): array
    {
        $out = [];
        $certs = Certificate::query()->ownedBy($user)->whereIn('status', ['issued', 'replaced', 'revoked'])->count();
        if ($certs > 0) {
            $out[] = ['code' => 'certificates', 'message' => "$certs sertifikat tetap sah; nama penerima tidak lagi tampil di halaman verifikasi publik."];
        }
        $memberId = $user->member?->id ?? 0;
        $orgs = EmploymentRelationship::query()->where('member_id', $memberId)->where('status', 'active')->with('organization')->get()
            ->map(fn (EmploymentRelationship $e) => $e->organization?->display_name ?? $e->organization?->legal_name)->filter()->unique();
        if ($orgs->isNotEmpty()) {
            $out[] = ['code' => 'employment', 'message' => 'Keterikatan sebagai karyawan '.$orgs->implode(', ').' diakhiri. Perusahaan tetap melihat riwayat pelatihan yang disponsori tanpa nama peserta.'];
        }
        $corporate = CorporateUser::query()->where('user_id', $user->id)->where('status', 'active')->count();
        if ($corporate > 0) {
            $out[] = ['code' => 'corporate_user', 'message' => 'Akses ke portal perusahaan dicabut.'];
        }

        return $out;
    }

    /**
     * Ringkasan data akun untuk admin.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $memberId = $user->member?->id ?? 0;
        $invoiceIds = $this->invoiceIds($user);

        return [
            'registrations' => Registration::query()->ownedBy($user)->count(),
            'enrollments' => Enrollment::query()->ownedBy($user)->count(),
            'enrollments_running' => Enrollment::query()->ownedBy($user)->whereIn('status', ['enrolled', 'attending'])->count(),
            'invoices' => count($invoiceIds),
            'invoices_unpaid' => Invoice::query()->whereIn('id', $invoiceIds)->whereIn('status', Invoice::PAYABLE)->count(),
            'certificates' => Certificate::query()->ownedBy($user)->whereIn('status', ['issued', 'replaced', 'revoked'])->count(),
            'organizations' => EmploymentRelationship::query()->where('member_id', $memberId)->with('organization')->get()
                ->map(fn (EmploymentRelationship $e) => trim(($e->organization?->display_name ?? $e->organization?->legal_name ?? '-').' ('.(EmploymentRelationship::STATUSES[$e->status] ?? $e->status).')'))->values()->all(),
            'corporate_access' => CorporateUser::query()->where('user_id', $user->id)->where('status', 'active')->count(),
        ];
    }

    /**
     * Ajukan permintaan. Bila ada penghalang, permintaan langsung tercatat sebagai ditolak otomatis dengan alasannya.
     *
     * @param  array{email?: ?string, password?: ?string, reason?: ?string}  $input
     */
    public function request(User $user, array $input): DataDeletionRequest
    {
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirmed = ($email !== '' && mb_strtolower($email) === mb_strtolower($user->email))
            || ($password !== '' && Hash::check($password, $user->password));
        if (! $confirmed) {
            throw ValidationException::withMessages([$password !== '' ? 'password' : 'email' => [$password !== '' ? 'Kata sandi tidak sesuai.' : 'Ketik alamat email akun Anda persis untuk konfirmasi.']]);
        }

        return DB::transaction(function () use ($user, $input): DataDeletionRequest {
            // kunci baris user agar dua permintaan bersamaan tidak sama-sama lolos cek "satu permintaan aktif"
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (DataDeletionRequest::query()->where('user_id', $user->id)->active()->exists()) {
                throw ValidationException::withMessages(['request' => ['Masih ada permintaan penghapusan data yang sedang diproses.']]);
            }

            $blockers = $this->blockers($user);
            $now = now();
            $req = DataDeletionRequest::query()->create([
                'user_id' => $user->id,
                'account_label' => $this->accountLabel($user),
                'status' => $blockers ? 'rejected' : 'pending',
                'auto_rejected' => $blockers !== [],
                'reason' => filled($input['reason'] ?? null) ? trim((string) $input['reason']) : null,
                'admin_note' => $blockers ? 'Ditolak otomatis: '.implode(' ', array_column($blockers, 'message')) : null,
                'blockers' => $blockers ?: null,
                'requested_at' => $now,
                'due_at' => $now->copy()->addDays($this->deadlineDays()),
                'processed_at' => $blockers ? $now : null,
            ]);
            AuditLog::record($blockers ? 'privacy.deletion_auto_rejected' : 'privacy.deletion_requested', $req, null,
                ['status' => $req->status, 'blockers' => array_column($blockers, 'code')], $user);

            DB::afterCommit(function () use ($req, $user, $blockers) {
                if ($blockers) {
                    $this->notifyParticipant($req, $user, 'privacy.deletion_rejected', ['reason' => implode(' ', array_column($blockers, 'message'))]);

                    return;
                }
                $this->notifyParticipant($req, $user, 'privacy.deletion_received', ['due' => IdDate::long($req->due_at)]);
                $this->safely(fn () => $this->center->publish('privacy.deletion_requested',
                    $this->recipients->admins('privacy.manage', null, [], NotificationRecipients::adminUrl(fn () => DataDeletionRequestResource::getUrl('view', ['record' => $req]))),
                    ['account' => $req->account_label, 'due' => IdDate::long($req->due_at), 'blockers' => ''],
                    ['subject' => $req, 'actor' => $user, 'dedupe' => 'privacy.deletion_requested:'.$req->id]));
            });

            return $req;
        });
    }

    /** Peserta membatalkan permintaan yang belum diproses. */
    public function cancel(User $user): DataDeletionRequest
    {
        $req = DataDeletionRequest::query()->where('user_id', $user->id)->where('status', 'pending')->latest('id')->first();
        if (! $req) {
            throw ValidationException::withMessages(['request' => ['Tidak ada permintaan yang bisa dibatalkan. Permintaan yang sudah diproses tidak dapat dibatalkan.']]);
        }
        $req->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        AuditLog::record('privacy.deletion_cancelled', $req, ['status' => 'pending'], ['status' => 'cancelled'], $user);

        return $req;
    }

    /**
     * Setujui lalu jalankan penghapusan. Penghalang dicek ulang: bila masih ada, persetujuan ditolak dengan alasannya.
     */
    public function approve(DataDeletionRequest $req, User $actor, ?string $note = null): DataDeletionRequest
    {
        $this->assertCanProcess($req, $actor);
        $user = $req->user;
        $blockers = $this->blockers($user);
        if ($blockers) {
            $req->update(['blockers' => $blockers]);
            throw ValidationException::withMessages(['blockers' => array_column($blockers, 'message')]);
        }

        $req->update([
            'status' => 'approved', 'processed_by' => $actor->id, 'processed_at' => now(),
            'admin_note' => filled($note) ? trim((string) $note) : $req->admin_note,
            'summary' => $this->summary($user) + ['warnings' => array_column($this->warnings($user), 'message')],
        ]);
        AuditLog::record('privacy.deletion_approved', $req, ['status' => 'pending'], ['status' => 'approved', 'note' => $req->admin_note], $actor);
        $this->notifyParticipant($req, $user, 'privacy.deletion_approved', ['note' => $req->admin_note ? 'Catatan admin: '.$req->admin_note : '']);

        $this->privacy->eraseAccount($user, $req, $actor);

        return $req->refresh();
    }

    public function reject(DataDeletionRequest $req, User $actor, string $reason): DataDeletionRequest
    {
        $this->assertCanProcess($req, $actor);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Alasan penolakan wajib diisi.']]);
        }
        $req->update(['status' => 'rejected', 'processed_by' => $actor->id, 'processed_at' => now(), 'admin_note' => $reason]);
        AuditLog::record('privacy.deletion_rejected', $req, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $reason], $actor);
        $this->notifyParticipant($req, $req->user, 'privacy.deletion_rejected', ['reason' => $reason]);

        return $req;
    }

    private function assertCanProcess(DataDeletionRequest $req, User $actor): void
    {
        if (! $actor->hasPermission('privacy.manage')) {
            throw ValidationException::withMessages(['request' => ['Anda tidak berwenang memproses permintaan penghapusan data.']]);
        }
        if ($req->status !== 'pending') {
            throw ValidationException::withMessages(['request' => ['Permintaan ini sudah diproses.']]);
        }
        if ($actor->is($req->user)) {
            throw ValidationException::withMessages(['request' => ['Permintaan atas akun sendiri diproses admin lain.']]);
        }
    }

    /** @return list<int> invoice milik pendaftaran akun */
    private function invoiceIds(User $user): array
    {
        return Invoice::query()->whereIn('registration_id', Registration::query()->ownedBy($user)->select('id'))->pluck('id')->all();
    }

    private function accountLabel(User $user): string
    {
        return $user->member?->member_number ?? 'Akun #'.$user->id;
    }

    /** @param array<string, scalar|null> $vars */
    private function notifyParticipant(DataDeletionRequest $req, User $user, string $key, array $vars): void
    {
        $this->safely(fn () => $this->center->publish($key, [Recipient::user($user)], ['name' => $user->name] + $vars, [
            'url' => '/portal/privasi', 'subject' => $req, 'only_channels' => ['database', 'mail'], 'dedupe' => "$key:{$req->id}",
        ]));
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
