<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\OrganizationUnit;
use App\Models\ParticipantInvitation;
use App\Models\RequestSeat;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Notifications\ParticipantInvited;
use App\Support\Enterprise\IdentityMatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Penugasan peserta ke kursi permintaan training (PRD Roadmap 5 §4.3, §20.4, §20.5):
 * manual, dari daftar karyawan, unggah massal (CSV/XLSX + laporan galat per baris), dan undangan bertoken.
 * Semua lewat IdentityMatchingService:
 * - Member terverifikasi (email terverifikasi / nomor member) → langsung ditetapkan, tanpa akun kedua.
 * - Kandidat tidak pasti / tidak ditemukan → UNDANGAN ke email peserta (peserta sendiri yang membuktikan identitasnya).
 *   Perusahaan tidak pernah melihat data member lain yang "mirip" (privasi §20.11).
 * - Data meragukan (konflik) → Review identitas VMES. Duplikat di permintaan/batch yang sama → ditolak.
 */
class ParticipantAssignmentService
{
    public const INVITE_DAYS = 14;

    public const IMPORT_COLUMNS = ['nama', 'email', 'hp', 'employee_id', 'nomor_member', 'departemen', 'jabatan'];

    public function __construct(
        private IdentityMatchingService $identity,
        private EmploymentService $employment,
        private MemberService $members,
        private EnrollmentService $enrollments,
    ) {}

    /**
     * @param  array{name?:?string, email?:?string, phone?:?string, employee_id?:?string, member_number?:?string, unit_id?:?int, position?:?string}  $person
     * @return array{status:string, message:string, seat:RequestSeat}
     */
    public function assign(RequestSeat $seat, CorporateUser $cu, array $person, string $method = 'manual'): array
    {
        $r = $seat->request;
        $this->guard($r, $cu);
        if (! $seat->isFree()) {
            throw ValidationException::withMessages(['seat' => "Kursi {$seat->seat_no} sudah terisi."]);
        }
        $person = $this->normalizePerson($r, $cu, $person);
        if (blank($person['name'])) {
            throw ValidationException::withMessages(['name' => 'Nama peserta wajib diisi.']);
        }
        if ($person['email'] && ! filter_var($person['email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Format email tidak valid.']);
        }
        $this->assertNotInRequest($r, null, $person['email']);

        $match = $this->identity->match($person, $r->organization, $r->schedule);

        return match ($match->outcome) {
            IdentityMatch::USE_EXISTING => $this->assignMember($seat, $cu->user, $match->member ?? $this->members->createForUser($match->user), $person, $method),
            IdentityMatch::DUPLICATE => throw ValidationException::withMessages(['member' => 'Peserta ini sudah terdaftar di batch yang sama.']),
            IdentityMatch::REVIEW => $this->toReview($seat, $cu, $match, $person),
            default => $this->invite($seat, $cu, $person),   // CONFIRM / EXISTING_EMPLOYMENT / CREATE_NEW
        };
    }

    /** Pilih dari karyawan aktif organisasi (identitas sudah terhubung). */
    public function assignEmployee(RequestSeat $seat, CorporateUser $cu, EmploymentRelationship $employment): array
    {
        $r = $seat->request;
        $this->guard($r, $cu);
        if ($employment->organization_id !== $r->organization_id || ! $employment->isActive()) {
            throw ValidationException::withMessages(['employment_id' => 'Karyawan tidak ditemukan di organisasi ini.']);
        }
        if (($scope = $cu->unitScope()) !== null && ! in_array($employment->unit_id, $scope, true)) {
            throw ValidationException::withMessages(['employment_id' => 'Karyawan di luar batas unit Anda.']);
        }
        if (! $seat->isFree()) {
            throw ValidationException::withMessages(['seat' => "Kursi {$seat->seat_no} sudah terisi."]);
        }

        return $this->assignMember($seat, $cu->user, $employment->member, ['unit_id' => $employment->unit_id], 'employee', $employment);
    }

    /** Kirim undangan bertoken ke email peserta. @return array{status:string, message:string, seat:RequestSeat, token?:string} */
    public function invite(RequestSeat $seat, CorporateUser $cu, array $person): array
    {
        if (blank($person['email'] ?? null)) {
            throw ValidationException::withMessages(['email' => 'Email peserta wajib diisi agar undangan bisa dikirim.']);
        }

        return DB::transaction(function () use ($seat, $cu, $person) {
            $token = Str::random(48);
            $inv = ParticipantInvitation::query()->create([
                'token_hash' => ParticipantInvitation::hashToken($token),
                'training_request_id' => $seat->training_request_id,
                'request_seat_id' => $seat->id,
                'organization_id' => $seat->request->organization_id,
                'email' => strtolower($person['email']),
                'name' => $person['name'] ?? null,
                'phone' => $person['phone'] ?? null,
                'employee_id' => $person['employee_id'] ?? null,
                'unit_id' => $person['unit_id'] ?? null,
                'position' => $person['position'] ?? null,
                'expires_at' => now()->addDays(self::INVITE_DAYS),
                'invited_by' => $cu->user_id,
            ]);
            $seat->update(['status' => 'invited', 'participant_name' => $person['name'] ?? null, 'participant_email' => $inv->email, 'unit_id' => $person['unit_id'] ?? $seat->unit_id, 'assignment_method' => 'invitation']);
            $inv->notify(new ParticipantInvited($inv, $token));
            AuditLog::record('participant.invited', $seat->request, null, ['seat' => $seat->seat_no, 'email' => $inv->email], $cu->user);

            return ['status' => 'invited', 'message' => "Undangan dikirim ke {$inv->email}.", 'seat' => $seat, 'token' => $token];
        });
    }

    /** Info undangan untuk halaman publik (tanpa data sensitif). */
    public function findInvitation(string $token): ParticipantInvitation
    {
        $inv = ParticipantInvitation::query()->where('token_hash', ParticipantInvitation::hashToken($token))->with(['request.package', 'request.schedule', 'organization'])->firstOrFail();
        if ($inv->status === 'pending' && $inv->expires_at->isPast()) {
            $inv->update(['status' => 'expired']);
        }

        return $inv;
    }

    /**
     * Peserta menerima undangan: harus masuk dengan email yang diundang (tautan dikirim ke email itu → email terverifikasi),
     * memberi consent, lalu ditetapkan ke kursi. Akun yang ada dipakai ulang.
     */
    public function acceptInvitation(string $token, User $user, bool $consent): RequestSeat
    {
        $inv = $this->findInvitation($token);
        if (! $inv->isUsable()) {
            throw ValidationException::withMessages(['token' => 'Undangan sudah tidak berlaku ('.(ParticipantInvitation::STATUSES[$inv->status] ?? $inv->status).').']);
        }
        if (strtolower($user->email) !== $inv->email) {
            throw ValidationException::withMessages(['email' => "Undangan ini untuk {$inv->email}. Masuk dengan akun email tersebut."]);
        }
        if (! $consent) {
            throw ValidationException::withMessages(['consent' => 'Persetujuan wajib: perusahaan akan melihat progres training yang mereka sponsori.']);
        }

        return DB::transaction(function () use ($inv, $user) {
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();   // tautan undangan dikirim ke email ini → kepemilikan email terbukti
                AuditLog::record('auth.email_verified', $user, null, ['via' => 'invitation'], $user);
            }
            $member = $user->member ?? $this->members->createForUser($user, ['phone' => $inv->phone]);
            $seat = $inv->seat()->lockForUpdate()->firstOrFail();
            $inv->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_user_id' => $user->id, 'consent_at' => now()]);
            $seat->update(['status' => 'reserved']);   // dibuka sebentar agar assignMember memvalidasi seperti biasa
            $this->assignMember($seat, $user, $member, ['employee_id' => $inv->employee_id, 'unit_id' => $inv->unit_id, 'position' => $inv->position], 'invitation');
            AuditLog::record('participant.invitation_accepted', $seat->request, null, ['seat' => $seat->seat_no, 'member_id' => $member->id], $user);

            return $seat->refresh();
        });
    }

    /** Batalkan peserta pada satu kursi — hanya enrollment kursi ini yang berubah (§11). Kursi kembali kosong. */
    public function releaseSeat(RequestSeat $seat, User $actor, string $reason, ?CorporateUser $cu = null): RequestSeat
    {
        if ($cu) {
            $this->guard($seat->request, $cu, allowClosed: false);
        }
        if ($seat->isFree()) {
            return $seat;
        }

        return DB::transaction(function () use ($seat, $actor, $reason) {
            $before = $seat->only(['status', 'member_id', 'enrollment_id', 'participant_name', 'participant_email']);
            if ($seat->enrollment && ! in_array($seat->enrollment->status, ['completed', 'dropped'], true)) {
                $this->enrollments->drop($seat->enrollment, $reason, $actor);
                $seat->request->schedule?->decrement('registered_count');
            }
            ParticipantInvitation::query()->where('request_seat_id', $seat->id)->where('status', 'pending')->update(['status' => 'revoked']);
            $seat->update(['status' => 'reserved', 'member_id' => null, 'employment_id' => null, 'enrollment_id' => null, 'participant_name' => null, 'participant_email' => null,
                'assigned_at' => null, 'assigned_by' => null, 'assignment_method' => null, 'unit_id' => $seat->request->unit_id]);
            AuditLog::record('request_seat.released', $seat->request, $before + ['seat' => $seat->seat_no], ['status' => 'reserved', 'reason' => $reason], $actor);

            return $seat;
        });
    }

    /** Buat enrollment CORPORATE bersponsor untuk kursi berisi member bila batch sudah ditetapkan. */
    public function enrollSeat(RequestSeat $seat, ?User $actor = null, bool $notify = true): ?Enrollment
    {
        $r = $seat->request;
        if (! $r->schedule_id || ! $seat->member_id || $seat->status === 'enrolled') {
            return $seat->enrollment;
        }
        $member = $seat->member()->with('user')->first();
        if ($this->identity->alreadyEnrolled($member, $r->schedule)) {
            AuditLog::record('request_seat.enroll_duplicate', $r, null, ['seat' => $seat->seat_no, 'member_id' => $member->id], $actor);

            return null;
        }
        $e = Enrollment::query()->create([
            'schedule_id' => $r->schedule_id,
            'member_id' => $member->id,
            'type' => Enrollment::TYPE_CORPORATE,
            'sponsor_organization_id' => $r->organization_id,
            'sponsor_unit_id' => $seat->unit_id ?? $r->unit_id,
            'participant_name' => $member->user?->name ?? $seat->participant_name,
            'participant_email' => $member->user?->email ?? $seat->participant_email,
            'enrolled_at' => now(),
            'notes' => "Corporate request {$r->code} kursi {$seat->seat_no}",
        ]);
        $r->schedule->increment('registered_count');
        $seat->update(['status' => 'enrolled', 'enrollment_id' => $e->id]);
        AuditLog::record('enrollment.created', $e, null, ['type' => 'corporate', 'sponsor_organization_id' => $r->organization_id, 'request' => $r->code, 'seat' => $seat->seat_no], $actor);
        if ($notify) {
            app(NotificationEvents::class)->sponsoredEnrollmentsCreated([$e]);
        }

        return $e;
    }

    /**
     * Unggah massal CSV/XLSX: validasi per baris, kursi kosong berikutnya, laporan per baris.
     *
     * @return list<array{row:int, name:?string, email:?string, status:string, message:string, seat:?int}>
     */
    public function import(TrainingRequest $r, CorporateUser $cu, UploadedFile $file): array
    {
        $this->guard($r, $cu);
        $rows = $this->readRows($file);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Berkas kosong atau kolom tidak dikenali. Gunakan template.']);
        }
        $report = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;   // baris 1 = judul kolom
            $person = [
                'name' => $row['nama'] ?? null, 'email' => $row['email'] ?? null, 'phone' => $row['hp'] ?? null,
                'employee_id' => $row['employee_id'] ?? null, 'member_number' => $row['nomor_member'] ?? null, 'position' => $row['jabatan'] ?? null,
            ];
            $base = ['row' => $line, 'name' => $person['name'], 'email' => $person['email'], 'seat' => null];
            $key = strtolower(trim((string) ($person['email'] ?: $person['member_number'] ?: $person['employee_id'] ?: '')));
            if ($key !== '' && isset($seen[$key])) {
                $report[] = $base + ['status' => 'error', 'message' => "Duplikat dengan baris {$seen[$key]} di berkas yang sama."];

                continue;
            }
            if (! empty($row['departemen'])) {
                $person['unit_id'] = OrganizationUnit::query()->where('organization_id', $r->organization_id)->whereRaw('LOWER(name) = ?', [strtolower(trim($row['departemen']))])->value('id');
            }
            $seat = $r->requestSeats()->where('status', 'reserved')->orderBy('seat_no')->first();
            if (! $seat) {
                $report[] = $base + ['status' => 'error', 'message' => 'Kursi habis — tambah kursi lewat VMES atau batalkan peserta lain.'];

                continue;
            }
            try {
                $res = $this->assign($seat, $cu, $person, 'bulk');
                if ($key !== '') {
                    $seen[$key] = $line;
                }
                $report[] = $base + ['status' => $res['status'], 'message' => $res['message'], 'seat' => $seat->seat_no];
            } catch (ValidationException $e) {
                $report[] = $base + ['status' => 'error', 'message' => collect($e->errors())->flatten()->first()];
            }
        }
        AuditLog::record('participant.bulk_imported', $r, null, ['rows' => count($rows), 'summary' => collect($report)->countBy('status')->all()], $cu->user);

        return $report;
    }

    /** Admin VMES menyelesaikan Review identitas untuk kursi: tetapkan member terpilih ke kursi itu. */
    public function assignFromReview(RequestSeat $seat, Member $member, User $actor, array $person = []): RequestSeat
    {
        if (! $seat->isFree()) {
            throw ValidationException::withMessages(['seat' => "Kursi {$seat->seat_no} sudah terisi."]);
        }
        $this->assignMember($seat, $actor, $member, $person, 'manual');

        return $seat->refresh();
    }

    public static function templateCsv(): string
    {
        return implode(',', self::IMPORT_COLUMNS)."\n"
            ."Budi Santoso,budi@perusahaan.co.id,081234567890,ABC-001,,Engineering,Teknisi\n";
    }

    // ---------------------------------------------------------------------

    private function assignMember(RequestSeat $seat, User $actor, Member $member, array $person, string $method, ?EmploymentRelationship $employment = null): array
    {
        $r = $seat->request;
        $this->assertNotInRequest($r, $member, null, $seat->id);
        if ($this->identity->alreadyEnrolled($member, $r->schedule)) {
            throw ValidationException::withMessages(['member' => 'Peserta ini sudah terdaftar di batch yang sama.']);
        }

        return DB::transaction(function () use ($seat, $actor, $member, $person, $method, $employment, $r) {
            $employment ??= EmploymentRelationship::query()->active()->where('member_id', $member->id)->where('organization_id', $r->organization_id)->first()
                ?? $this->employment->start($member, $r->organization, [
                    'employee_id' => $person['employee_id'] ?? null, 'unit_id' => $person['unit_id'] ?? null, 'position' => $person['position'] ?? null,
                ], $method === 'invitation' ? 'invitation' : ($method === 'bulk' ? 'bulk_upload' : 'corporate_portal'), $actor);
            $member->loadMissing('user');
            $seat->update([
                'status' => 'assigned', 'member_id' => $member->id, 'employment_id' => $employment->id, 'unit_id' => $person['unit_id'] ?? $employment->unit_id ?? $seat->unit_id,
                'participant_name' => $member->user?->name, 'participant_email' => $member->user?->email, 'assigned_at' => now(), 'assigned_by' => $actor->id, 'assignment_method' => $method,
            ]);
            AuditLog::record('participant.assigned', $r, null, ['seat' => $seat->seat_no, 'member_id' => $member->id, 'method' => $method], $actor);
            $this->enrollSeat($seat->refresh(), $actor);

            return ['status' => 'assigned', 'message' => 'Peserta ditetapkan ke kursi '.$seat->seat_no.'.', 'seat' => $seat->refresh()];
        });
    }

    private function toReview(RequestSeat $seat, CorporateUser $cu, IdentityMatch $match, array $person): array
    {
        if ($match->reason === 'incomplete') {
            throw ValidationException::withMessages(['email' => 'Isi email atau nomor HP peserta.']);
        }
        if ($match->reason === 'unknown_member_number') {
            throw ValidationException::withMessages(['member_number' => 'Nomor member tidak ditemukan.']);
        }
        $this->identity->queueReview($match, $person, $seat, $seat->request->organization, $seat->request->schedule);
        AuditLog::record('participant.review_queued', $seat->request, null, ['seat' => $seat->seat_no, 'reason' => $match->reason], $cu->user);

        return ['status' => 'review', 'message' => 'Data peserta perlu diperiksa tim VMES sebelum ditetapkan. Kursi tetap kosong.', 'seat' => $seat];
    }

    private function assertNotInRequest(TrainingRequest $r, ?Member $member, ?string $email, ?int $exceptSeat = null): void
    {
        $q = $r->requestSeats()->where('status', '!=', 'reserved')->when($exceptSeat, fn ($w) => $w->whereKeyNot($exceptSeat));
        if ($member && (clone $q)->where('member_id', $member->id)->exists()) {
            throw ValidationException::withMessages(['member' => 'Peserta ini sudah ada di permintaan yang sama.']);
        }
        if ($email && (clone $q)->whereRaw('LOWER(participant_email) = ?', [strtolower($email)])->exists()) {
            throw ValidationException::withMessages(['email' => 'Email ini sudah ada di permintaan yang sama.']);
        }
    }

    private function normalizePerson(TrainingRequest $r, CorporateUser $cu, array $p): array
    {
        $p = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $p) + ['name' => null, 'email' => null, 'unit_id' => null];
        if (! empty($p['unit_id'])) {
            $unit = OrganizationUnit::query()->find($p['unit_id']);
            if (! $unit || $unit->organization_id !== $r->organization_id) {
                throw ValidationException::withMessages(['unit_id' => 'Unit bukan milik organisasi ini.']);
            }
        }
        $p['unit_id'] ??= $r->unit_id ?? $cu->unit_id;
        if (($scope = $cu->unitScope()) !== null && ! in_array($p['unit_id'], $scope, true)) {
            throw ValidationException::withMessages(['unit_id' => 'Unit di luar batas akses Anda.']);
        }

        return $p;
    }

    private function guard(TrainingRequest $r, CorporateUser $cu, bool $allowClosed = false): void
    {
        if (! $cu->isActive() || $cu->organization_id !== $r->organization_id || ! $cu->allows('manage_participants')) {
            throw ValidationException::withMessages(['role' => 'Peran Anda di perusahaan tidak mengizinkan penugasan peserta.']);
        }
        if (($scope = $cu->unitScope()) !== null && ! in_array($r->unit_id, $scope, true)) {
            throw ValidationException::withMessages(['role' => 'Permintaan ini di luar batas unit Anda.']);
        }
        if (! $allowClosed && ! $r->isAssignable()) {
            throw ValidationException::withMessages(['status' => "Permintaan berstatus \"{$r->label()}\" — peserta tidak bisa diubah."]);
        }
    }

    /** @return list<array<string,string>> baris dengan kunci kolom template */
    private function readRows(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'xlsx') {
            $reader = new XlsxReader;
        } else {
            $opts = new CsvOptions;
            $opts->FIELD_DELIMITER = $this->sniffDelimiter($file->getRealPath());
            $reader = new CsvReader($opts);
        }
        $reader->open($file->getRealPath());
        $rows = [];
        $header = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)), $row->toArray());
                if ($header === null) {
                    $header = array_map(fn ($h) => Str::of($h)->lower()->replace([' ', '-', '.'], '_')->replaceMatches('/^\x{FEFF}/u', '')->toString(), $cells);

                    continue;
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                $assoc = [];
                foreach ($header as $i => $h) {
                    if (in_array($h, self::IMPORT_COLUMNS, true)) {
                        $assoc[$h] = $cells[$i] ?? '';
                    }
                }
                $rows[] = $assoc;
                if (count($rows) > 500) {
                    throw ValidationException::withMessages(['file' => 'Maksimal 500 baris per unggahan.']);
                }
            }
            break;   // hanya sheet pertama
        }
        $reader->close();

        return $header && array_intersect(['nama', 'email'], $header) ? $rows : [];
    }

    private function sniffDelimiter(string $path): string
    {
        $first = (string) fgets(fopen($path, 'r'));

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }
}
