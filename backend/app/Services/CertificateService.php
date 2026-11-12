<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\CompetencyAssessment;
use App\Models\Enrollment;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\CertificateIssued;
use App\Support\IdDate;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Certificate Management (PRD §5.10): draf dari enrollment yang lulus, validasi pejabat berwenang, terbit (PDF + QR),
 * cabut / ganti, verifikasi publik, riwayat penerbitan di audit_logs.
 */
class CertificateService
{
    public function __construct(private readonly CompletionService $completion) {}

    // ------------------------------------------------------------------ kelayakan & draf

    /**
     * Kelayakan sertifikat untuk enrollment: status lulus, aturan kelulusan paket, kompetensi (untuk jenis kompetensi), belum punya sertifikat aktif.
     *
     * @return array{eligible: bool, reasons: string[], evaluation: array<string, mixed>, existing: ?Certificate, type: string}
     */
    public function eligibility(Enrollment $e, ?string $type = null): array
    {
        $e->loadMissing('schedule.package');
        $type ??= $e->schedule->package?->certificate_type ?? 'participation';
        $eval = $this->completion->evaluate($e);
        $reasons = [];
        // Keputusan kelulusan (CompletionService::complete, boleh dipaksa admin) adalah gerbang penerbitan;
        // rincian aturan hanya ditampilkan bila peserta belum ditetapkan lulus.
        if ($e->status !== 'completed') {
            $reasons[] = 'Peserta belum ditetapkan lulus (status: '.$e->status.').';
            foreach ($eval['checks'] as $c) {
                if ($c['required'] && ! $c['ok']) {
                    $reasons[] = $c['label'].' — '.$c['detail'];
                }
            }
        }
        if ($type === 'competency' && ! (($eval['competency']['validated'] ?? false) && ($eval['competency']['competent'] ?? false))) {
            $reasons[] = 'Sertifikat kompetensi memerlukan penilaian kompetensi tervalidasi dengan hasil kompeten.';
        }
        $existing = Certificate::query()->activeForEnrollment($e->id)->first();
        if ($existing) {
            $reasons[] = "Sudah ada sertifikat aktif {$existing->number} ({$existing->statusLabel()}).";
        }

        return ['eligible' => $reasons === [], 'reasons' => array_values(array_unique($reasons)), 'evaluation' => $eval, 'existing' => $existing, 'type' => $type];
    }

    /**
     * Buat draf sertifikat dari enrollment. $force (admin) melewati aturan kelulusan; alasan dicatat di audit log.
     *
     * @param  array<string, mixed>  $overrides  type, template_id, recipient_name, program_title, issued_at, valid_until, hours, score, predicate, competencies, signatory_name, signatory_title, notes
     */
    public function createDraft(Enrollment $e, User $actor, array $overrides = [], bool $force = false, ?string $forceReason = null): Certificate
    {
        $e->loadMissing('schedule.package', 'member.user');
        $type = $overrides['type'] ?? $e->schedule->package?->certificate_type ?? 'participation';
        $check = $this->eligibility($e, $type);
        if ($check['existing']) {
            throw ValidationException::withMessages(['enrollment_id' => ["Sudah ada sertifikat aktif {$check['existing']->number} untuk peserta ini. Cabut atau ganti sertifikat tersebut."]]);
        }
        if (! $check['eligible'] && ! $force) {
            throw ValidationException::withMessages(['enrollment_id' => $check['reasons']]);
        }
        $template = ! empty($overrides['template_id']) ? CertificateTemplate::query()->findOrFail($overrides['template_id']) : CertificateTemplate::resolveFor($e->schedule->package, $type);
        $s = $e->schedule;
        $p = $s->package;
        $issuedAt = ! empty($overrides['issued_at']) ? Carbon::parse($overrides['issued_at']) : ($s->end_date?->isPast() ? $s->end_date->copy() : today());
        $validUntil = array_key_exists('valid_until', $overrides) && $overrides['valid_until'] !== null && $overrides['valid_until'] !== ''
            ? Carbon::parse($overrides['valid_until'])
            : ($template->validity_months ? $issuedAt->copy()->addMonths($template->validity_months) : null);
        $eval = $check['evaluation'];
        $competencies = $overrides['competencies'] ?? $this->defaultCompetencies($e);
        $predicate = $overrides['predicate'] ?? ($type === 'competency' ? 'Kompeten' : ($eval['score'] !== null ? self::predicate((float) $eval['score']) : null));

        return DB::transaction(function () use ($e, $actor, $overrides, $force, $forceReason, $template, $type, $s, $p, $issuedAt, $validUntil, $eval, $competencies, $predicate, $check) {
            $cert = Certificate::query()->create([
                'number' => Certificate::nextNumber($template, $type, $issuedAt),
                'verification_code' => Certificate::newVerificationCode(),
                'template_id' => $template->id,
                'enrollment_id' => $e->id,
                'member_id' => $e->member_id,
                'schedule_id' => $s->id,
                'package_id' => $p?->id,
                'type' => $type,
                'recipient_name' => $overrides['recipient_name'] ?? $e->participant_name,
                'recipient_email' => $e->participant_email ?? $e->member?->user?->email,
                'program_title' => $overrides['program_title'] ?? ($s->title ?: $p?->title ?: 'Pelatihan'),
                'class_code' => $s->code,
                'start_date' => $s->start_date,
                'end_date' => $s->end_date,
                'city' => $s->city,
                'hours' => $overrides['hours'] ?? $p?->duration_hours,
                'score' => array_key_exists('score', $overrides) ? $overrides['score'] : ($e->final_score !== null ? (float) $e->final_score : $eval['score']),
                'predicate' => $predicate,
                'competencies' => array_values(array_filter((array) $competencies)),
                'issuer_name' => $template->issuer_name,
                'signatory_name' => $overrides['signatory_name'] ?? $template->signatory_name,
                'signatory_title' => $overrides['signatory_title'] ?? $template->signatory_title,
                'issued_at' => $issuedAt->toDateString(),
                'valid_until' => $validUntil?->toDateString(),
                'status' => 'draft',
                'created_by' => $actor->id,
                'notes' => $overrides['notes'] ?? null,
            ]);
            AuditLog::record('certificate.created', $cert, null, [
                'number' => $cert->number, 'type' => $type, 'enrollment_id' => $e->id, 'recipient' => $cert->recipient_name,
                'eligible' => $check['eligible'], 'forced' => $force && ! $check['eligible'], 'force_reason' => $forceReason, 'reasons' => $check['reasons'],
            ], $actor);

            return $cert;
        });
    }

    /**
     * Buat draf untuk semua peserta lulus satu kelas yang memenuhi syarat & belum punya sertifikat aktif.
     *
     * @return array{created: Certificate[], skipped: array<int, array{name: string, reasons: string[]}>}
     */
    public function createDraftsForSchedule(TrainingSchedule $schedule, User $actor, ?string $type = null): array
    {
        $created = $skipped = [];
        foreach ($schedule->enrollments()->with('schedule.package')->get() as $e) {
            $check = $this->eligibility($e, $type);
            if (! $check['eligible']) {
                $skipped[] = ['name' => $e->participant_name, 'reasons' => $check['reasons']];

                continue;
            }
            $created[] = $this->createDraft($e, $actor, $type ? ['type' => $type] : []);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @return string[] */
    private function defaultCompetencies(Enrollment $e): array
    {
        $p = $e->schedule->package;
        $assessment = CompetencyAssessment::query()->where('enrollment_id', $e->id)->with('rubric.criteria')->first();
        if ($assessment?->isValidated()) {
            $list = $assessment->rubric->criteria->pluck('competency')->filter()->values()->all();
            if ($list !== []) {
                return $list;
            }
        }
        $list = array_values(array_filter((array) ($p?->competencies ?? [])));
        if ($list !== []) {
            return $list;
        }

        $list = $p ? $p->curricula()->pluck('title')->all() : [];

        return $list !== [] ? $list : $e->schedule->sessions()->whereNotNull('topic')->pluck('topic')->unique()->values()->all();
    }

    public static function predicate(float $score): string
    {
        return match (true) {
            $score >= 90 => 'Sangat Baik',
            $score >= 80 => 'Baik',
            $score >= 70 => 'Cukup',
            default => 'Lulus',
        };
    }

    // ------------------------------------------------------------------ alur status

    /** Draf → menunggu validasi (koordinator). */
    public function submit(Certificate $c, User $actor): Certificate
    {
        if ($c->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Hanya draf yang dapat diajukan untuk validasi.']]);
        }
        $c->update(['status' => 'pending_validation', 'submitted_by' => $actor->id, 'submitted_at' => now()]);
        AuditLog::record('certificate.submitted', $c, ['status' => 'draft'], ['status' => 'pending_validation'], $actor);

        return $c->refresh();
    }

    /** Kembalikan ke draf (ditolak validator) dengan catatan. */
    public function reject(Certificate $c, User $actor, string $reason): Certificate
    {
        if ($c->status !== 'pending_validation') {
            throw ValidationException::withMessages(['status' => ['Hanya sertifikat yang menunggu validasi yang dapat dikembalikan.']]);
        }
        $c->update(['status' => 'draft', 'notes' => trim(($c->notes ? $c->notes."\n" : '').'Dikembalikan: '.$reason)]);
        AuditLog::record('certificate.rejected', $c, ['status' => 'pending_validation'], ['status' => 'draft', 'reason' => $reason], $actor);

        return $c->refresh();
    }

    /** Terbitkan (pejabat berwenang): buat PDF, status issued, email ke penerima. Draf boleh langsung terbit oleh pejabat berwenang. */
    public function issue(Certificate $c, User $actor, bool $notify = true): Certificate
    {
        if (! in_array($c->status, ['draft', 'pending_validation'], true)) {
            throw ValidationException::withMessages(['status' => ['Sertifikat sudah '.$c->statusLabel().'.']]);
        }
        if (! $actor->hasPermission('certificates.issue')) {
            throw ValidationException::withMessages(['status' => ['Anda tidak berwenang menerbitkan sertifikat.']]);
        }

        return DB::transaction(function () use ($c, $actor, $notify) {
            $before = ['status' => $c->status];
            $c->fill(['status' => 'issued', 'issued_by' => $actor->id, 'issued_on' => now()]);
            if (! $c->issued_at) {
                $c->issued_at = today();
            }
            $c->save();
            $this->storePdf($c);
            AuditLog::record('certificate.issued', $c, $before, ['status' => 'issued', 'number' => $c->number, 'pdf' => $c->pdf_path], $actor);
            if ($notify && $c->recipient_email) {
                Notification::route('mail', [$c->recipient_email => $c->recipient_name])->notify(new CertificateIssued($c));
            }
            if ($notify) {
                app(NotificationEvents::class)->certificateIssued($c, $actor);
            }
            $e = $c->enrollment;
            if ($e && $e->member) {
                app(MemberService::class)->syncStatus($e->member);
            }

            return $c->refresh();
        });
    }

    public function revoke(Certificate $c, User $actor, string $reason): Certificate
    {
        if ($c->status !== 'issued') {
            throw ValidationException::withMessages(['status' => ['Hanya sertifikat terbit yang dapat dicabut.']]);
        }
        $c->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'revoke_reason' => $reason]);
        AuditLog::record('certificate.revoked', $c, ['status' => 'issued'], ['status' => 'revoked', 'reason' => $reason], $actor);

        return $c->refresh();
    }

    /** Ganti sertifikat terbit (mis. salah nama): yang lama → replaced, draf baru dengan nomor baru mewarisi data. */
    public function replace(Certificate $old, User $actor, string $reason, array $overrides = []): Certificate
    {
        if (! in_array($old->status, ['issued', 'revoked'], true)) {
            throw ValidationException::withMessages(['status' => ['Hanya sertifikat terbit/dicabut yang dapat diganti.']]);
        }

        return DB::transaction(function () use ($old, $actor, $reason, $overrides) {
            $template = $old->template ?? CertificateTemplate::resolveFor($old->package, $old->type);
            $issuedAt = ! empty($overrides['issued_at']) ? Carbon::parse($overrides['issued_at']) : today();
            $new = $old->replicate(['number', 'verification_code', 'status', 'pdf_path', 'pdf_generated_at', 'submitted_by', 'submitted_at', 'issued_by', 'issued_on', 'revoked_by', 'revoked_at', 'revoke_reason', 'replaces_id', 'replaced_by_id']);
            $new->fill(array_intersect_key($overrides, array_flip(['recipient_name', 'program_title', 'score', 'predicate', 'hours', 'signatory_name', 'signatory_title', 'notes'])));
            $new->fill([
                'number' => Certificate::nextNumber($template, $old->type, $issuedAt),
                'verification_code' => Certificate::newVerificationCode(),
                'status' => 'draft',
                'issued_at' => $issuedAt->toDateString(),
                'valid_until' => array_key_exists('valid_until', $overrides) ? $overrides['valid_until'] : ($template->validity_months ? $issuedAt->copy()->addMonths($template->validity_months)->toDateString() : $old->valid_until?->toDateString()),
                'replaces_id' => $old->id,
                'created_by' => $actor->id,
                'notes' => trim(($overrides['notes'] ?? '')."\nMenggantikan {$old->number}: {$reason}"),
            ]);
            $new->save();
            $old->update(['status' => 'replaced', 'replaced_by_id' => $new->id, 'revoke_reason' => $old->revoke_reason ?: $reason]);
            AuditLog::record('certificate.replaced', $old, ['status' => 'issued'], ['status' => 'replaced', 'replaced_by' => $new->number, 'reason' => $reason], $actor);
            AuditLog::record('certificate.created', $new, null, ['number' => $new->number, 'replaces' => $old->number, 'reason' => $reason], $actor);

            return $new;
        });
    }

    // ------------------------------------------------------------------ PDF & QR

    /** Render PDF (bytes). Draf/menunggu validasi diberi tanda air PRATINJAU. */
    public function renderPdf(Certificate $c): string
    {
        $c->loadMissing('template');
        $template = $c->template ?? CertificateTemplate::resolveFor($c->package, $c->type);

        return Pdf::loadView('certificates.default', $this->viewData($c, $template))
            ->setPaper('a4', $template->orientation === 'portrait' ? 'portrait' : 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->output();
    }

    /** Simpan PDF ke storage (certificates/YYYY/NOMOR.pdf) dan catat di sertifikat. */
    public function storePdf(Certificate $c, ?User $actor = null): Certificate
    {
        $disk = config('filesystems.default');
        $path = 'certificates/'.($c->issued_at?->format('Y') ?? now()->format('Y')).'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $c->number).'.pdf';
        Storage::disk($disk)->put($path, $this->renderPdf($c), ['visibility' => 'private']);
        $c->forceFill(['pdf_path' => $path, 'pdf_generated_at' => now()])->save();
        if ($actor) {
            AuditLog::record('certificate.regenerated', $c, null, ['pdf' => $path], $actor);
        }

        return $c;
    }

    /** Isi PDF: tersimpan bila ada & terbit, selain itu dirender ulang. */
    public function pdfContents(Certificate $c): string
    {
        $disk = Storage::disk(config('filesystems.default'));
        if ($c->isIssued() && $c->pdf_path && $disk->exists($c->pdf_path)) {
            return (string) $disk->get($c->pdf_path);
        }

        return $this->renderPdf($c);
    }

    public function fileName(Certificate $c): string
    {
        return 'Sertifikat-'.preg_replace('/[^A-Za-z0-9_-]/', '_', $c->number).'.pdf';
    }

    /** SVG kode QR (data URI) untuk URL verifikasi. */
    public static function qrDataUri(string $payload, int $size = 220): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd)))->writeString($payload);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** @return array<string, mixed> */
    public function viewData(Certificate $c, CertificateTemplate $template): array
    {
        $img = fn (?string $path) => $path ? self::imageDataUri($path) : null;
        $vars = [
            '{name}' => $c->recipient_name, '{program}' => $c->program_title, '{class_code}' => $c->class_code ?? '',
            '{start_date}' => IdDate::long($c->start_date) ?? '', '{end_date}' => IdDate::long($c->end_date) ?? '', '{city}' => $c->city ?? '',
            '{hours}' => $c->hours !== null ? $c->hours.' JP' : '', '{score}' => $c->score !== null ? (string) (float) $c->score : '', '{issuer}' => $c->issuer_name ?: $template->issuer_name,
            '{predicate}' => $c->predicate ?? '',
        ];

        return [
            'c' => $c,
            't' => $template,
            'body' => strtr($template->body ?: CertificateTemplate::DEFAULT_BODY[$c->type] ?? '', $vars),
            'typeLabel' => $c->typeLabel(),
            'issuedLabel' => IdDate::long($c->issued_at ?? today()),
            'validLabel' => $c->valid_until ? IdDate::long($c->valid_until) : null,
            'periodLabel' => $c->start_date && $c->end_date ? ($c->start_date->equalTo($c->end_date) ? IdDate::long($c->start_date) : IdDate::long($c->start_date).' - '.IdDate::long($c->end_date)) : null,
            'qr' => self::qrDataUri($c->verificationUrl()),
            'verifyUrl' => $c->verificationUrl(),
            'logo' => $img($template->logo_image) ?? (file_exists(public_path('images/logo.jpeg')) ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents(public_path('images/logo.jpeg'))) : null),
            'background' => $img($template->background_image),
            'signature' => $img($template->signature_image),
            'signature2' => $img($template->second_signature_image),
            'preview' => ! $c->isIssued() && $c->status !== 'replaced',
            'competencies' => $template->show_competencies ? ($c->competencies ?? []) : [],
        ];
    }

    private static function imageDataUri(string $path): ?string
    {
        try {
            $disk = Storage::disk(config('filesystems.default'));
            if (! $disk->exists($path)) {
                return null;
            }
            $mime = $disk->mimeType($path) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Sertifikat contoh untuk pratinjau template (tidak disimpan). */
    public function sample(CertificateTemplate $template): Certificate
    {
        $c = new Certificate([
            'number' => strtr($template->number_format ?: 'PPTI-{TYPE}-{YYYY}{MM}-{SEQ}', ['{TYPE}' => CertificateTemplate::TYPE_CODES[$template->type] ?? 'PST', '{YYYY}' => now()->format('Y'), '{YY}' => now()->format('y'), '{MM}' => now()->format('m'), '{SEQ}' => '00001']),
            'verification_code' => 'CONTOH1234', 'type' => $template->type, 'recipient_name' => 'Nama Peserta', 'program_title' => 'Cisco CCNA: Routing & Switching', 'class_code' => 'CCNA-2611',
            'start_date' => today()->subDays(4), 'end_date' => today(), 'city' => 'Batam', 'hours' => 40, 'score' => 88, 'predicate' => $template->type === 'competency' ? 'Kompeten' : 'Baik',
            'competencies' => ['Konsep dasar jaringan', 'Konfigurasi router & switch', 'VLAN & inter-VLAN routing', 'Static & dynamic routing', 'Troubleshooting jaringan'],
            'issuer_name' => $template->issuer_name, 'signatory_name' => $template->signatory_name, 'signatory_title' => $template->signatory_title,
            'issued_at' => today(), 'valid_until' => $template->validity_months ? today()->addMonths($template->validity_months) : null, 'status' => 'issued',
        ]);
        $c->setRelation('template', $template);

        return $c;
    }

    // ------------------------------------------------------------------ verifikasi & JSON

    /** Hasil verifikasi publik (PRD §5.10, AC-10). @return array<string, mixed> */
    public function verify(Certificate $c, ?string $code = null): array
    {
        $c->loadMissing('replacedBy');
        $status = $c->isExpired() ? 'expired' : $c->status;
        $labels = Certificate::STATUSES + ['expired' => 'Kedaluwarsa'];
        $pending = in_array($c->status, ['draft', 'pending_validation'], true);

        return [
            'number' => $c->number,
            'status' => $pending ? 'pending' : $status,
            'statusLabel' => $pending ? 'Belum diterbitkan' : ($labels[$status] ?? $status),
            'valid' => $c->isValid(),
            'codeMatches' => $code === null ? null : hash_equals($c->verification_code, strtoupper($code)),
            'type' => $c->type,
            'typeLabel' => $c->typeLabel(),
            'recipientName' => $pending ? null : $c->recipient_name,
            'programTitle' => $c->program_title,
            'classCode' => $c->class_code,
            'periodLabel' => $c->start_date && $c->end_date ? IdDate::long($c->start_date).' - '.IdDate::long($c->end_date) : null,
            'city' => $c->city,
            'hours' => $c->hours,
            'predicate' => $pending ? null : $c->predicate,
            'issuer' => $c->issuer_name,
            'signatory' => $c->signatory_name ? trim($c->signatory_name.($c->signatory_title ? ', '.$c->signatory_title : '')) : null,
            'issuedAt' => $pending ? null : $c->issued_at?->toDateString(),
            'issuedLabel' => $pending ? null : IdDate::long($c->issued_at),
            'validUntil' => $c->valid_until?->toDateString(),
            'validUntilLabel' => $c->valid_until ? IdDate::long($c->valid_until) : null,
            'revokedAt' => $c->revoked_at?->toDateString(),
            'replacedBy' => $c->replacedBy?->number,
            'competencies' => $pending ? [] : ($c->competencies ?? []),
        ];
    }

    /** Bentuk JSON sertifikat untuk portal member/admin. @return array<string, mixed> */
    public static function toArray(Certificate $c): array
    {
        $c->loadMissing('schedule', 'replacedBy', 'replaces');

        return [
            'id' => $c->id, 'number' => $c->number, 'type' => $c->type, 'typeLabel' => $c->typeLabel(),
            'status' => $c->status, 'statusLabel' => $c->statusLabel(), 'isValid' => $c->isValid(), 'isExpired' => $c->isExpired(),
            'recipientName' => $c->recipient_name, 'programTitle' => $c->program_title, 'classCode' => $c->class_code,
            'periodLabel' => $c->start_date && $c->end_date ? IdDate::long($c->start_date).' - '.IdDate::long($c->end_date) : null,
            'city' => $c->city, 'hours' => $c->hours, 'score' => $c->score !== null ? (float) $c->score : null, 'predicate' => $c->predicate, 'competencies' => $c->competencies ?? [],
            'issuer' => $c->issuer_name, 'signatory' => $c->signatory_name, 'signatoryTitle' => $c->signatory_title,
            'issuedAt' => $c->issued_at?->toDateString(), 'issuedLabel' => $c->issued_at ? IdDate::long($c->issued_at) : null,
            'validUntil' => $c->valid_until?->toDateString(), 'validUntilLabel' => $c->valid_until ? IdDate::long($c->valid_until) : null,
            'revokedAt' => $c->revoked_at?->toIso8601String(), 'revokeReason' => $c->status === 'revoked' ? $c->revoke_reason : null,
            'replacedBy' => $c->replacedBy?->number, 'replaces' => $c->replaces?->number,
            'verificationUrl' => $c->verificationUrl(), 'downloadable' => $c->isIssued(), 'enrollmentId' => $c->enrollment_id,
            'createdAt' => $c->created_at?->toIso8601String(),
        ];
    }

    /** Riwayat penerbitan dari audit log. @return Collection<int, array<string, mixed>> */
    public static function history(Certificate $c): Collection
    {
        return $c->history()->get()->map(fn (AuditLog $l) => ['action' => $l->action, 'actor' => $l->actor_name, 'at' => $l->created_at?->toIso8601String(), 'atLabel' => $l->created_at?->format('d M Y H:i'), 'after' => $l->after])->values();
    }
}
