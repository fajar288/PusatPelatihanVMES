<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\RegistrationReceived;
use App\Notifications\RegistrationStatusChanged;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Alur pendaftaran (PRD §5.5): buat pendaftaran, ubah status dengan validasi transisi, catat audit, kirim notifikasi. */
class RegistrationService
{
    public function __construct(private readonly MemberService $members, private readonly EnrollmentService $enrollments, private readonly InvoiceService $invoices) {}

    /**
     * @param  array<string, mixed>  $data  hasil StoreRegistrationRequest
     * @param  array<int, array<string, mixed>>  $participants  untuk kolektif
     * @param  array<string, mixed>  $attributes  kolom tambahan (mis. api_client_id & external_ref dari API Publik)
     */
    public function create(array $data, TrainingSchedule $schedule, ?User $user = null, array $participants = [], string $source = 'web', array $attributes = []): Registration
    {
        $type = $data['type'] ?? Registration::TYPE_INDIVIDUAL;
        $count = $type === Registration::TYPE_CORPORATE ? max(1, (int) ($data['participants_count'] ?? count($participants) ?: 1)) : 1;
        $price = $schedule->effectivePrice();
        $files = array_values(array_filter((array) ($data['documents'] ?? []), fn ($f) => $f instanceof UploadedFile));
        $stored = [];

        try {
            return DB::transaction(function () use ($data, $schedule, $user, $participants, $source, $type, $count, $price, $attributes, $files, &$stored) {
                // satu akun = satu member: pengguna login yang belum punya profil member dibuatkan otomatis
                $member = $user ? ($user->member ?? $this->members->createForUser($user)) : null;

                $registration = Registration::query()->create([
                    'registration_number' => Registration::nextNumber(),
                    'schedule_id' => $schedule->id,
                    'package_id' => $schedule->package_id,
                    'member_id' => $member?->id,
                    'user_id' => $user?->id,
                    'type' => $type,
                    'status' => RegistrationStatus::Pending,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'company' => $data['company'] ?? null,
                    'position' => $data['position'] ?? null,
                    'participants_count' => $count,
                    'notes' => $data['notes'] ?? null,
                    'consent' => (bool) ($data['consent'] ?? false),
                    'price_quoted' => $price,
                    'discount' => 0,
                    'total' => $price !== null ? $price * $count : null,
                    'source' => $source,
                ] + $attributes);

                foreach ($participants as $p) {
                    $registration->participants()->create([
                        'name' => $p['name'], 'email' => $p['email'] ?? null, 'phone' => $p['phone'] ?? null, 'position' => $p['position'] ?? null,
                    ]);
                }

                if ($files) {
                    $this->storeDocuments($registration, $files, $stored);
                }

                AuditLog::record('registration.created', $registration, null, ['status' => 'pending', 'number' => $registration->registration_number, 'documents' => count($files)], $user);
                $registration->notify(new RegistrationReceived($registration));
                app(NotificationEvents::class)->registrationReceived($registration);

                return $registration;
            });
        } catch (\Throwable $e) {
            // transaksi dibatalkan: berkas yang sudah tersimpan tidak boleh tertinggal tanpa pemilik
            $stored && Storage::disk(Registration::DOCUMENT_DISK)->delete($stored);
            throw $e;
        }
    }

    /**
     * Pendaftar menambah dokumen pendukung dari portal selama pendaftaran belum terkonfirmasi/final.
     *
     * @param  list<UploadedFile>  $files
     */
    public function addDocuments(Registration $registration, array $files, User $actor): Registration
    {
        if (! $registration->status->acceptsDocuments()) {
            throw ValidationException::withMessages(['documents' => 'Dokumen tidak bisa ditambahkan pada status '.$registration->status->label().'.']);
        }
        $existing = count($registration->documentList());
        if ($existing + count($files) > Registration::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['documents' => 'Maksimal '.Registration::MAX_DOCUMENTS.' dokumen per pendaftaran (sudah ada '.$existing.').']);
        }

        $stored = [];
        try {
            DB::transaction(function () use ($registration, $files, $actor, &$stored) {
                $this->storeDocuments($registration, $files, $stored);
                AuditLog::record('registration.documents_added', $registration, null, ['documents' => array_map(fn (UploadedFile $f) => $f->getClientOriginalName(), $files)], $actor);
            });
        } catch (\Throwable $e) {
            $stored && Storage::disk(Registration::DOCUMENT_DISK)->delete($stored);
            throw $e;
        }

        return $registration->refresh();
    }

    /**
     * Simpan berkas ke disk privat `registrations/{nomor}` dengan nama acak; nama asli dicatat di `document_names`.
     *
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $stored  diisi path yang berhasil disimpan (untuk dibersihkan bila transaksi gagal)
     */
    private function storeDocuments(Registration $registration, array $files, array &$stored): void
    {
        $paths = array_values(array_filter((array) ($registration->documents ?? []), 'is_string'));
        $names = (array) ($registration->document_names ?? []);
        foreach ($files as $file) {
            $path = $file->store('registrations/'.$registration->registration_number, Registration::DOCUMENT_DISK);
            if ($path === false) {
                throw new \RuntimeException('Dokumen pendukung gagal disimpan.');
            }
            $stored[] = $path;
            $paths[] = $path;
            $names[$path] = mb_substr($file->getClientOriginalName(), 0, 200);
        }
        $registration->forceFill(['documents' => $paths, 'document_names' => $names])->save();
    }

    /** Ubah status dengan validasi transisi; buat tagihan saat masuk "menunggu pembayaran". */
    public function transition(Registration $registration, RegistrationStatus $to, ?User $actor = null, ?string $note = null): Registration
    {
        $from = $registration->status;
        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => "Transisi {$from->label()} → {$to->label()} tidak diizinkan."]);
        }
        // Roadmap 5B: pendaftaran kolektif wajib ditautkan admin ke organisasi sebelum dikonfirmasi (sponsor enrollment)
        if ($to === RegistrationStatus::Confirmed && $registration->type === Registration::TYPE_CORPORATE && ! $registration->organization_id) {
            throw ValidationException::withMessages(['organization_id' => 'Tautkan pendaftaran kolektif ini ke organisasi (menu Pendaftaran → Tautkan organisasi) sebelum dikonfirmasi.']);
        }

        return DB::transaction(function () use ($registration, $from, $to, $actor, $note) {
            $registration->forceFill([
                'status' => $to,
                'status_note' => $note ?? $registration->status_note,
                'reviewed_by' => $actor?->id ?? $registration->reviewed_by,
                'reviewed_at' => now(),
                'confirmed_at' => $to === RegistrationStatus::Confirmed ? now() : $registration->confirmed_at,
            ])->save();

            if ($to === RegistrationStatus::WaitingPayment) {
                $this->invoices->forRegistration($registration, $actor);   // idempoten (Roadmap 5C)
            }

            if ($to === RegistrationStatus::Confirmed) {
                $registration->schedule()->increment('registered_count', $registration->participants_count);
                $this->activateMember($registration);
                $this->enrollments->fromRegistration($registration, $actor);
            }

            AuditLog::record('registration.status_changed', $registration, ['status' => $from->value], ['status' => $to->value, 'note' => $note], $actor);
            $registration->notify(new RegistrationStatusChanged($registration, $from));
            app(NotificationEvents::class)->registrationStatusChanged($registration, $from);

            return $registration->refresh();
        });
    }

    /** Pendaftar yang sudah punya akun → member aktif (konversi pendaftar → member, PRD §5.5). */
    private function activateMember(Registration $registration): void
    {
        $member = $registration->member ?? ($registration->user_id ? Member::query()->where('user_id', $registration->user_id)->first() : null);
        if ($member && $member->status === 'registered') {
            $member->update(['status' => 'active']);
        }
    }
}
