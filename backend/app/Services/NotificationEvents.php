<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\CompetencyAssessment;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Registration;
use App\Models\ScheduleChange;
use App\Models\TrainingSchedule;
use App\Support\IdDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Peristiwa sistem → notifikasi aplikasi/push/email lewat NotificationCenter (PRD §5.12).
 * Dipanggil dari service/observer domain; kegagalan notifikasi tidak pernah menggagalkan proses bisnis.
 */
class NotificationEvents
{
    private const FIELD_LABELS = [
        'start_date' => 'tanggal mulai', 'end_date' => 'tanggal selesai', 'start_time' => 'jam mulai', 'end_time' => 'jam selesai',
        'city' => 'kota', 'location' => 'lokasi', 'mode' => 'metode', 'status' => 'status kelas', 'trainer_name' => 'trainer',
    ];

    public function __construct(private readonly NotificationCenter $center) {}

    public function registrationReceived(Registration $r): void
    {
        $this->safely(fn () => $this->center->send(NotificationCenter::usersForRegistration($r), 'registration.received', $this->registrationVars($r), '/portal/pendaftaran/'.$r->registration_number));
    }

    public function registrationStatusChanged(Registration $r, RegistrationStatus $from): void
    {
        $this->safely(fn () => $this->center->send(NotificationCenter::usersForRegistration($r), 'registration.status_changed', $this->registrationVars($r) + [
            'status' => $r->status->label(), 'previous' => $from->label(), 'note' => $r->status_note ? 'Catatan: '.$r->status_note : '',
        ], '/portal/pendaftaran/'.$r->registration_number));
    }

    public function scheduleChanged(TrainingSchedule $s, ScheduleChange $change): void
    {
        $this->safely(function () use ($s, $change): void {
            $parts = [];
            foreach ((array) $change->after as $field => $value) {
                $parts[] = (self::FIELD_LABELS[$field] ?? $field).' '.($change->before[$field] ?? '-').' → '.($value ?? '-');
            }
            $regs = $s->registrations()->whereNotIn('status', [RegistrationStatus::Rejected->value, RegistrationStatus::Cancelled->value])->get();
            $users = $regs->flatMap(fn (Registration $r) => NotificationCenter::usersForRegistration($r))
                ->merge(NotificationCenter::usersForEnrollments($s->enrollments()->whereIn('status', ['enrolled', 'attending'])->get()));
            $this->center->send($users, 'schedule.changed', [
                'class' => $s->code, 'program' => $s->title ?? $s->package?->title,
                'changes' => ucfirst(implode('; ', $parts)), 'reason' => $change->reason ? 'Alasan: '.$change->reason : '',
            ], '/portal/pelatihan');
        });
    }

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

    public function certificateIssued(Certificate $c): void
    {
        $this->safely(function () use ($c): void {
            if ($c->enrollment) {
                $this->center->send(NotificationCenter::usersForEnrollment($c->enrollment), 'certificate.issued', [
                    'number' => $c->number, 'program' => $c->program_title,
                ], '/portal/sertifikat/'.$c->number);
            }
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

    /** Pengingat H-1 kelas dimulai. @return int jumlah penerima */
    public function classReminders(?CarbonInterface $day = null): int
    {
        $day ??= today()->addDay();
        $count = 0;
        $schedules = TrainingSchedule::query()->with('package')->whereDate('start_date', $day)
            ->whereIn('status', ['registration_open', 'registration_closed', 'confirmed'])->get();
        foreach ($schedules as $s) {
            $users = NotificationCenter::usersForEnrollments($s->enrollments()->whereIn('status', ['enrolled', 'attending'])->get());
            $count += $this->center->send($users, 'class.reminder', [
                'program' => $s->title ?? $s->package?->title, 'class' => $s->code, 'date' => IdDate::long($s->start_date),
                'time' => $s->timeLabel() ?? 'sesuai jadwal', 'location' => $s->location ?: ($s->city ?: ucfirst((string) $s->mode)),
            ], '/portal/pelatihan', "class.reminder:{$s->id}:".$s->start_date->toDateString());
        }

        return $count;
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
        $r->loadMissing('schedule.package');

        return ['number' => $r->registration_number, 'program' => $r->schedule?->title ?? $r->schedule?->package?->title, 'class' => $r->schedule?->code];
    }
}
