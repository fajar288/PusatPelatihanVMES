<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Registrations\RegistrationResource;
use App\Filament\Resources\TrainingSchedules\TrainingScheduleResource;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\CompetencyAssessment;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Registration;
use App\Models\ScheduleChange;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Support\IdDate;
use App\Support\Notify\Recipient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Katalog business event → Notification Engine (PRD §5.12 + Roadmap 5 §6).
 *
 * | Event              | Peserta          | Perusahaan (hanya sponsored) | Trainer / Admin VMES |
 * |--------------------|------------------|------------------------------|----------------------|
 * | Registration       | WA + app (+email transaksional) | email bila sponsored | admin: dashboard |
 * | Payment Success    | WA + email + app | email (finance perusahaan)   | admin: dashboard     |
 * | H-7 / H-1          | WA + email + app | email                        | trainer: WA/email    |
 * | Schedule Change    | WA + app (+email transaksional) | WA + email    | trainer: WA + email  |
 * | Progress Risk      | WA/email + app   | email bila sponsored         | admin: dashboard     |
 * | Training Completed | WA + email + app | email                        | admin: dashboard     |
 * | Certificate Issued | WA + app (+email transaksional) | email         | admin: dashboard     |
 *
 * Dipanggil dari service/observer domain; kegagalan notifikasi tidak pernah menggagalkan proses bisnis.
 */
class NotificationEvents
{
    private const FIELD_LABELS = [
        'start_date' => 'tanggal mulai', 'end_date' => 'tanggal selesai', 'start_time' => 'jam mulai', 'end_time' => 'jam selesai',
        'city' => 'kota', 'location' => 'lokasi', 'mode' => 'metode', 'status' => 'status kelas', 'trainer_name' => 'trainer',
    ];

    public function __construct(private readonly NotificationCenter $center, private readonly NotificationRecipients $recipients) {}

    // ================================================================= Registration

    public function registrationReceived(Registration $r): void
    {
        $this->safely(function () use ($r): void {
            $vars = $this->registrationVars($r);
            $url = '/portal/pendaftaran/'.$r->registration_number;
            $recipients = NotificationCenter::usersForRegistration($r)->map(fn (User $u) => Recipient::user($u, Recipient::PARTICIPANT, [], null, $url))
                ->merge($this->recipients->corporateForOrganization($r->organization, 'view_progress', [], $r->organization ? '/corporate/'.$r->organization->code : null))
                ->merge($this->recipients->admins('registrations.manage', null, ['applicant' => $r->name],
                    NotificationRecipients::adminUrl(fn () => RegistrationResource::getUrl('edit', ['record' => $r]))));
            $this->center->publish('registration.received', $recipients, $vars, ['url' => $url, 'subject' => $r, 'dedupe' => 'registration.received:'.$r->id]);
        });
    }

    public function registrationStatusChanged(Registration $r, RegistrationStatus $from): void
    {
        $this->safely(fn () => $this->center->publish('registration.status_changed',
            NotificationCenter::usersForRegistration($r)->map(fn (User $u) => Recipient::user($u)),
            $this->registrationVars($r) + ['status' => $r->status->label(), 'previous' => $from->label(), 'note' => $r->status_note ? 'Catatan: '.$r->status_note : ''],
            ['url' => '/portal/pendaftaran/'.$r->registration_number, 'subject' => $r]));
    }

    /**
     * Enrollment CORPORATE baru (penugasan kursi, request dijadwalkan, pendaftaran kolektif dikonfirmasi):
     * peserta diberi tahu bahwa perusahaan mendaftarkannya; pengguna perusahaan menerima ringkasan per kelas.
     *
     * @param  iterable<Enrollment>  $enrollments
     */
    public function sponsoredEnrollmentsCreated(iterable $enrollments, bool $notifyCorporate = true): void
    {
        $this->safely(function () use ($enrollments, $notifyCorporate): void {
            $list = collect($enrollments)->filter(fn ($e) => $e instanceof Enrollment && $e->isCorporate())->each->loadMissing('schedule.package', 'sponsor');
            foreach ($list->groupBy('schedule_id') as $group) {
                $s = $group->first()->schedule;
                $recipients = $group->flatMap(fn (Enrollment $e) => NotificationCenter::usersForEnrollment($e)
                    ->map(fn (User $u) => Recipient::user($u, Recipient::PARTICIPANT, ['organization' => $e->sponsor?->name() ?? 'Perusahaan Anda'])));
                if ($notifyCorporate) {
                    $recipients = $recipients->merge($this->recipients->corporate($group));
                }
                $this->center->publish('enrollment.created', $recipients, $this->classVars($s), [
                    'url' => '/portal/pelatihan', 'subject' => $s,
                    'dedupe' => 'enrollment.created:'.$s->id.':'.md5($group->pluck('id')->sort()->implode(',')),
                ]);
            }
        });
    }

    // ================================================================= Payment Success

    public function paymentSucceeded(Invoice $invoice, ?User $actor = null): void
    {
        $this->safely(function () use ($invoice, $actor): void {
            $invoice->loadMissing('organization', 'registration.schedule.package', 'registration.organization', 'trainingRequest.organization', 'trainingRequest.package');
            $reg = $invoice->registration;
            $req = $invoice->trainingRequest;
            $org = $invoice->organization ?? $req?->organization ?? $reg?->organization;
            $program = $reg?->schedule?->title ?? $reg?->schedule?->package?->title ?? $req?->package?->title ?? 'pelatihan';
            $vars = [
                'invoice' => $invoice->invoice_number, 'amount' => PaymentService::rupiah((float) $invoice->amount_paid), 'program' => $program,
                'payer' => $org?->name() ?? $reg?->name ?? $invoice->bill_to_name, 'verifier' => $actor?->name ?? 'sistem',
            ];
            $recipients = ($reg ? NotificationCenter::usersForRegistration($reg) : collect())
                ->map(fn (User $u) => Recipient::user($u, Recipient::PARTICIPANT, [], null, '/portal/pembayaran/'.$invoice->invoice_number))
                ->merge($this->recipients->corporateForOrganization($org, 'view_finance', [], $org ? '/corporate/'.$org->code.'/keuangan/'.$invoice->invoice_number : null))
                ->merge($this->recipients->admins('payments.verify', $actor, [], NotificationRecipients::adminUrl(fn () => InvoiceResource::getUrl('edit', ['record' => $invoice]))));
            $this->center->publish('payment.success', $recipients, $vars, ['subject' => $invoice, 'actor' => $actor, 'dedupe' => 'payment.success:'.$invoice->id]);
        });
    }

    // ================================================================= H-7 / H-1

    /** Pengingat H-1 kelas dimulai. @return int jumlah penerima (semua scope) */
    public function classReminders(?CarbonInterface $day = null, string $key = 'class.reminder'): int
    {
        $day ??= today()->addDay();
        $count = 0;
        $schedules = TrainingSchedule::query()->with('package')->whereDate('start_date', $day)
            ->whereIn('status', ['registration_open', 'registration_closed', 'confirmed'])->get();
        foreach ($schedules as $s) {
            try {
                $enrollments = $s->enrollments()->whereIn('status', ['enrolled', 'attending'])->get();
                $vars = $this->classVars($s) + ['count' => (string) $enrollments->count()];
                $recipients = $this->recipients->participants($enrollments)
                    ->merge($this->recipients->corporate($enrollments))
                    ->merge($enrollments->isNotEmpty() ? $this->recipients->trainers($s) : collect());
                $count += $this->center->publish($key, $recipients, $vars, [
                    'url' => '/portal/pelatihan', 'subject' => $s, 'actor' => null, 'dedupe' => "$key:{$s->id}:".$s->start_date->toDateString(),
                ])['recipients'];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $count;
    }

    /** Pengingat H-7. @return int jumlah penerima */
    public function classRemindersH7(?CarbonInterface $day = null): int
    {
        return $this->classReminders($day ?? today()->addDays(7), 'class.reminder_h7');
    }

    // ================================================================= Schedule Change

    public function scheduleChanged(TrainingSchedule $s, ScheduleChange $change): void
    {
        $this->safely(function () use ($s, $change): void {
            $parts = [];
            foreach ((array) $change->after as $field => $value) {
                $parts[] = (self::FIELD_LABELS[$field] ?? $field).' '.($change->before[$field] ?? '-').' → '.($value ?? '-');
            }
            $regs = $s->registrations()->whereNotIn('status', [RegistrationStatus::Rejected->value, RegistrationStatus::Cancelled->value])->get();
            $enrollments = $s->enrollments()->whereIn('status', ['enrolled', 'attending'])->get();
            $users = $regs->flatMap(fn (Registration $r) => NotificationCenter::usersForRegistration($r))
                ->merge(NotificationCenter::usersForEnrollments($enrollments))->unique('id');
            $recipients = $users->map(fn (User $u) => Recipient::user($u))
                ->merge($this->recipients->corporate($enrollments))
                ->merge($this->recipients->trainers($s));
            $this->center->publish('schedule.changed', $recipients, [
                'class' => $s->code, 'program' => $s->title ?? $s->package?->title,
                'changes' => ucfirst(implode('; ', $parts)), 'reason' => $change->reason ? 'Alasan: '.$change->reason : '',
            ], ['url' => '/portal/pelatihan', 'subject' => $change, 'dedupe' => 'schedule.changed:'.$change->id]);
        });
    }

    // ================================================================= Progress Risk

    /**
     * Peringatan peserta berisiko tertinggal (aturan sama dengan Dashboard Eksekutif: kehadiran, progres LMS, kuis, tidak aktif).
     * Satu peringatan per enrollment per `notifications.risk.repeat_days`. @return int jumlah enrollment yang diperingatkan
     */
    public function progressRisk(): int
    {
        if (! config('notifications.risk.enabled', true)) {
            return 0;
        }
        $rows = app(AnalyticsService::class)->dashboard(['from' => today()->toDateString(), 'to' => today()->toDateString()])['learning']['at_risk'] ?? [];
        $period = intdiv((int) today()->timestamp, 86400 * max(1, (int) config('notifications.risk.repeat_days', 7)));
        $warned = collect();
        foreach ($rows as $row) {
            try {
                $e = Enrollment::query()->with('schedule.package')->find($row['enrollment_id']);
                if (! $e) {
                    continue;
                }
                $reasons = implode('; ', $row['reasons']);
                $vars = ['program' => $row['program'], 'class' => $row['class'], 'reasons' => $reasons, 'participant' => $row['name']];
                $recipients = $this->recipients->participants([$e])
                    ->merge($this->recipients->corporate([$e], 'view_progress', true, fn ($org) => '/corporate/'.$org->code.'/peserta/'.$e->id));
                $r = $this->center->publish('progress.risk', $recipients, $vars, [
                    'url' => '/portal/progres', 'subject' => $e, 'actor' => null, 'dedupe' => "progress.risk:{$e->id}:$period",
                ]);
                if ($r['deliveries'] > 0) {
                    $warned->push($row);
                }
            } catch (Throwable $ex) {
                report($ex);
            }
        }
        if ($warned->isNotEmpty()) {
            $this->safely(fn () => $this->center->publish('progress.risk', $this->recipients->admins('monitoring.manage'), [
                'count' => (string) $warned->count(),
                'participants' => NotificationRecipients::nameList($warned->map(fn ($r) => $r['name'].' ('.$r['class'].')'), 8),
            ], ['url' => '/admin/dashboard-eksekutif', 'actor' => null, 'dedupe' => 'progress.risk:admin:'.today()->toDateString()]));
        }

        return $warned->count();
    }

    // ================================================================= Training Completed

    public function trainingCompleted(Enrollment $e, ?User $actor = null): void
    {
        $this->safely(function () use ($e, $actor): void {
            if (! in_array($e->status, ['completed', 'failed'], true)) {
                return;
            }
            $e->loadMissing('schedule.package');
            $s = $e->schedule;
            $pass = $e->status === 'completed';
            $score = $e->final_score !== null ? rtrim(rtrim(number_format((float) $e->final_score, 1, ',', ''), '0'), ',') : null;
            $vars = [
                'program' => $s?->title ?? $s?->package?->title, 'class' => $s?->code, 'participant' => $e->participant_name,
                'result' => $pass ? 'Lulus' : 'Tidak lulus',
                'score' => $score !== null ? 'Nilai akhir: '.$score.'.' : '',
                'detail' => ($score !== null ? 'Nilai akhir: '.$score.'. ' : '').($pass ? 'Sertifikat akan diterbitkan setelah divalidasi.' : 'Hubungi PPTI untuk informasi remedial/kelas ulang.'),
                'next' => $pass ? 'Sertifikat dapat diproses.' : 'Peserta tidak lulus.',
            ];
            $recipients = $this->recipients->participants([$e])
                ->merge($this->recipients->corporate([$e], 'view_progress', false, fn ($org) => '/corporate/'.$org->code.'/peserta/'.$e->id))
                ->merge($this->recipients->admins('certificates.manage', $actor, [], $s ? NotificationRecipients::adminUrl(fn () => TrainingScheduleResource::getUrl('edit', ['record' => $s])) : null));
            $this->center->publish('training.completed', $recipients, $vars, [
                'url' => '/portal/progres', 'subject' => $e, 'actor' => $actor, 'dedupe' => "training.completed:{$e->id}:{$e->status}",
            ]);
        });
    }

    // ================================================================= Certificate Issued

    public function certificateIssued(Certificate $c, ?User $actor = null): void
    {
        $this->safely(function () use ($c, $actor): void {
            $e = $c->enrollment;
            if (! $e) {
                return;
            }
            $actor ??= auth()->user() instanceof User ? auth()->user() : null;
            $recipients = $this->recipients->participants([$e])
                ->merge($this->recipients->corporate([$e], 'view_progress', false, fn ($org) => '/corporate/'.$org->code.'/sertifikat'))
                ->merge($this->recipients->admins('certificates.manage', $actor, ['issuer' => $actor?->name ?? 'sistem'], '/admin/certificates'));
            $this->center->publish('certificate.issued', $recipients, [
                'number' => $c->number, 'program' => $c->program_title, 'participant' => $c->recipient_name ?? $e->participant_name,
            ], ['url' => '/portal/sertifikat/'.$c->number, 'subject' => $c, 'actor' => $actor, 'dedupe' => 'certificate.issued:'.$c->id]);
        });
    }

    // ================================================================= Pembelajaran (peserta)

    public function assignmentGraded(AssignmentSubmission $sub): void
    {
        $this->safely(function () use ($sub): void {
            $sub->loadMissing('lesson.module.course', 'enrollment');
            $lesson = $sub->lesson;
            $course = $lesson?->module?->course;
            $max = (float) ($lesson?->config()['max_score'] ?? 100);
            $graded = $sub->status === 'graded';
            $this->center->send(NotificationCenter::usersForEnrollment($sub->enrollment), 'assignment.graded', [
                'lesson' => $lesson?->title, 'course' => $course?->title,
                'result' => $graded ? 'sudah dinilai' : 'dikembalikan untuk diperbaiki',
                'score' => $graded ? 'Nilai: '.rtrim(rtrim(number_format((float) $sub->score, 2, ',', ''), '0'), ',').'/'.rtrim(rtrim(number_format($max, 2, ',', ''), '0'), ',').'.' : '',
                'feedback' => $sub->feedback ? 'Catatan: '.$sub->feedback : '',
            ], $course && $lesson ? "/portal/belajar/{$course->slug}/{$lesson->id}" : '/portal/tugas');
        });
    }

    public function assessmentValidated(CompetencyAssessment $a): void
    {
        $this->safely(function () use ($a): void {
            $a->loadMissing('enrollment.schedule.package');
            $s = $a->enrollment?->schedule;
            $this->center->send(NotificationCenter::usersForEnrollment($a->enrollment), 'assessment.validated', [
                'program' => $s?->title ?? $s?->package?->title, 'class' => $s?->code,
                'score' => rtrim(rtrim(number_format((float) $a->total_percent, 1, ',', ''), '0'), ','),
                'result' => $a->is_competent ? 'Kompeten' : 'Belum kompeten',
            ], '/portal/progres');
        });
    }

    /** Lesson baru terbit/tersedia → peserta aktif kelas yang memakai course tersebut (sekali per lesson). */
    public function lessonPublished(Lesson $lesson): void
    {
        $this->safely(function () use ($lesson): void {
            $lesson->loadMissing('module.course');
            $course = $lesson->module?->course;
            if (! $course || ! $course->is_published || ! $lesson->isAvailableNow()) {
                return;
            }
            $schedules = TrainingSchedule::query()->where('package_id', $course->package_id)
                ->when($course->schedule_id, fn ($q) => $q->whereKey($course->schedule_id))
                ->whereDate('end_date', '>=', today())->pluck('id');
            $enrollments = Enrollment::query()->whereIn('schedule_id', $schedules)->whereIn('status', ['enrolled', 'attending'])->get();
            $this->center->send(NotificationCenter::usersForEnrollments($enrollments), 'lesson.published', [
                'lesson' => $lesson->title, 'course' => $course->title,
            ], "/portal/belajar/{$course->slug}/{$lesson->id}", 'lesson.published:'.$lesson->id);
        });
    }

    /** Pengingat tugas yang tenggatnya dalam 24 jam dan belum dikumpulkan. @return int jumlah penerima */
    public function assignmentDueReminders(): int
    {
        $count = 0;
        $lessons = Lesson::query()->where('type', 'assignment')->where('is_published', true)->with('module.course')->get()
            ->filter(function (Lesson $l) {
                $due = $l->config()['due_at'] ?? null;
                if (! $due) {
                    return false;
                }
                $at = Carbon::parse($due);

                return $at->isFuture() && $at->lte(now()->addDay());
            });
        foreach ($lessons as $lesson) {
            $course = $lesson->module?->course;
            if (! $course?->is_published) {
                continue;
            }
            $schedules = TrainingSchedule::query()->where('package_id', $course->package_id)
                ->when($course->schedule_id, fn ($q) => $q->whereKey($course->schedule_id))
                ->whereDate('end_date', '>=', today()->subDays(30))->pluck('id');
            $enrollments = Enrollment::query()->whereIn('schedule_id', $schedules)->whereIn('status', ['enrolled', 'attending'])
                ->whereDoesntHave('submissions', fn ($q) => $q->where('lesson_id', $lesson->id))->get();
            $due = Carbon::parse($lesson->config()['due_at']);
            $count += $this->center->send(NotificationCenter::usersForEnrollments($enrollments), 'assignment.due', [
                'lesson' => $lesson->title, 'course' => $course->title,
                'due' => IdDate::long($due).' '.$due->format('H.i').' WIB',
            ], "/portal/belajar/{$course->slug}/{$lesson->id}", "assignment.due:{$lesson->id}");
        }

        return $count;
    }

    /** @param callable(): mixed $fn */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, string|null> */
    private function registrationVars(Registration $r): array
    {
        $r->loadMissing('schedule.package', 'organization');

        return ['number' => $r->registration_number, 'program' => $r->schedule?->title ?? $r->schedule?->package?->title, 'class' => $r->schedule?->code];
    }

    /** @return array<string, string|null> */
    private function classVars(?TrainingSchedule $s): array
    {
        if (! $s) {
            return [];
        }

        return [
            'program' => $s->title ?? $s->package?->title, 'class' => $s->code, 'date' => IdDate::long($s->start_date),
            'time' => $s->timeLabel() ?? 'sesuai jadwal', 'location' => $s->location ?: ($s->city ?: ucfirst((string) $s->mode)),
        ];
    }
}
