<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\SurveyResponse;
use App\Models\SurveyTemplate;
use App\Models\TrainingSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Survei evaluasi pasca-kelas (tanpa AI): undangan otomatis lewat Notification Engine, pengisian di portal member,
 * dan laporan per kelas / trainer / program. Tetap berjalan walau fitur AI mati.
 */
class SurveyService
{
    /** Kelas yang selesai dalam N hari terakhir masih diundang. */
    public const INVITE_WINDOW_DAYS = 30;

    public const COMMENT_MAX = 2000;

    /** Minimal survei terisi sebelum skor per pertanyaan dan komentar dibuka ke trainer (jawaban tidak bisa ditebak). */
    public const TRAINER_MIN_RESPONSES = 3;

    public function __construct(private readonly NotificationCenter $center, private readonly NotificationRecipients $recipients) {}

    /** Undang peserta kelas yang sudah selesai (lulus/tidak lulus) dan belum punya survei. Aman diulang. */
    public function inviteDue(): int
    {
        $template = SurveyTemplate::default();
        $count = 0;
        Enrollment::query()->whereIn('status', ['completed', 'failed'])->whereDoesntHave('surveyResponse')
            ->whereHas('schedule', fn ($q) => $q->whereDate('end_date', '<=', today())->whereDate('end_date', '>=', today()->subDays(self::INVITE_WINDOW_DAYS)))
            ->with('schedule.package')->orderBy('id')
            ->each(function (Enrollment $e) use ($template, &$count): void {
                $response = SurveyResponse::query()->create([
                    'template_id' => $template->id, 'schedule_id' => $e->schedule_id, 'enrollment_id' => $e->id, 'member_id' => $e->member_id,
                    'status' => 'pending', 'invited_at' => now(),
                ]);
                $count++;
                try {
                    $s = $e->schedule;
                    $this->center->publish('survey.invite', $this->recipients->participants([$e]), [
                        'program' => $s->title ?? $s->package?->title, 'class' => $s->code,
                    ], ['url' => '/portal/survei/'.$response->id, 'subject' => $e, 'actor' => null, 'dedupe' => 'survey.invite:'.$e->id]);
                } catch (Throwable $ex) {
                    report($ex);
                }
            });

        return $count;
    }

    /** Survei milik pengguna (lewat member). @return Collection<int, SurveyResponse> */
    public function forUser(User $user): Collection
    {
        $memberId = $user->member?->id;

        return $memberId ? SurveyResponse::query()->where('member_id', $memberId)->with(['schedule.package', 'template'])->latest('id')->get() : collect();
    }

    /**
     * Simpan jawaban. Skala wajib 1-5 untuk pertanyaan wajib; komentar maks. 2000 karakter. Hanya sekali.
     *
     * @param  array<string, mixed>  $answers
     */
    public function submit(SurveyResponse $response, array $answers): SurveyResponse
    {
        if ($response->status === 'submitted') {
            throw ValidationException::withMessages(['answers' => 'Survei ini sudah diisi. Terima kasih.']);
        }
        $clean = [];
        $errors = [];
        foreach ($response->template->questionList() as $q) {
            $v = $answers[$q['key']] ?? null;
            if ($q['type'] === 'scale') {
                if ($v === null || $v === '') {
                    if ($q['required']) {
                        $errors['answers.'.$q['key']] = 'Pilih nilai 1-5.';
                    }

                    continue;
                }
                if (! is_numeric($v) || (int) $v < 1 || (int) $v > 5) {
                    $errors['answers.'.$q['key']] = 'Nilai harus 1-5.';

                    continue;
                }
                $clean[$q['key']] = (int) $v;
            } else {
                $text = trim((string) $v);
                if ($text === '' && $q['required']) {
                    $errors['answers.'.$q['key']] = 'Wajib diisi.';
                }
                if (mb_strlen($text) > self::COMMENT_MAX) {
                    $errors['answers.'.$q['key']] = 'Maksimal '.self::COMMENT_MAX.' karakter.';
                }
                if ($text !== '') {
                    $clean[$q['key']] = $text;
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $response->update(['answers' => $clean, 'status' => 'submitted', 'submitted_at' => now()]);

        return $response;
    }

    /**
     * Laporan satu kelas: tingkat respons, rata-rata per pertanyaan, dan komentar tanpa nama.
     *
     * @return array<string, mixed>
     */
    public function classReport(TrainingSchedule $s): array
    {
        $rows = SurveyResponse::query()->where('schedule_id', $s->id)->with('template')->get();
        $submitted = $rows->where('status', 'submitted');
        $template = $submitted->first()?->template ?? SurveyTemplate::default();
        $questions = [];
        $comments = [];
        foreach ($template->questionList() as $q) {
            if ($q['type'] === 'scale') {
                $vals = $submitted->map(fn ($r) => $r->answers[$q['key']] ?? null)->filter(fn ($v) => is_numeric($v));
                $questions[] = ['key' => $q['key'], 'label' => $q['label'], 'average' => $vals->isEmpty() ? null : round((float) $vals->avg(), 2), 'count' => $vals->count()];
            } else {
                foreach ($submitted as $r) {
                    if (filled($r->answers[$q['key']] ?? null)) {
                        $comments[] = ['responseId' => $r->id, 'question' => $q['label'], 'questionKey' => $q['key'], 'text' => (string) $r->answers[$q['key']]];
                    }
                }
            }
        }

        return [
            'invited' => $rows->count(),
            'submitted' => $submitted->count(),
            'responseRate' => $rows->count() > 0 ? round($submitted->count() / $rows->count() * 100, 1) : null,
            'average' => $submitted->isEmpty() ? null : round((float) $submitted->map->averageScore()->filter()->avg(), 2),
            'questions' => $questions,
            'comments' => $comments,
        ];
    }

    /**
     * Ringkasan per trainer atau per program dari survei terisi: jumlah respons dan rata-rata skor.
     *
     * @return list<array{key: int, name: string, responses: int, average: ?float}>
     */
    public function summaryBy(string $dimension): array
    {
        $responses = SurveyResponse::query()->where('status', 'submitted')->with(['template', 'schedule.package:id,title'])->get();
        if ($dimension === 'trainer') {
            $links = DB::table('class_trainers')->join('trainers', 'trainers.id', '=', 'class_trainers.trainer_id')
                ->whereIn('class_trainers.schedule_id', $responses->pluck('schedule_id')->unique())->get(['class_trainers.schedule_id', 'trainers.id', 'trainers.name']);
            $groups = [];
            foreach ($links as $l) {
                foreach ($responses->where('schedule_id', $l->schedule_id) as $r) {
                    $groups[$l->id]['name'] = $l->name;
                    $groups[$l->id]['scores'][] = $r->averageScore();
                }
            }
        } else {
            $groups = [];
            foreach ($responses as $r) {
                $pid = (int) $r->schedule?->package_id;
                $groups[$pid]['name'] = $r->schedule?->package?->title ?? '-';
                $groups[$pid]['scores'][] = $r->averageScore();
            }
        }
        $out = [];
        foreach ($groups as $key => $g) {
            $scores = array_filter($g['scores'], fn ($v) => $v !== null);
            $out[] = ['key' => (int) $key, 'name' => $g['name'], 'responses' => count($g['scores']), 'average' => $scores ? round(array_sum($scores) / count($scores), 2) : null];
        }
        usort($out, fn ($a, $b) => [$b['responses'], $a['name']] <=> [$a['responses'], $b['name']]);

        return $out;
    }
}
