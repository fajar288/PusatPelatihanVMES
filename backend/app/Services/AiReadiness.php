<?php

namespace App\Services;

use App\Support\Ai\AiUseCases;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kesiapan data per use case: jumlah data riil dibandingkan ambang minimum (config/ai.php `readiness`).
 * Selama ambang belum tercapai, hasil evaluasi hanya membuktikan pipeline berjalan dan UI menampilkan "Data belum cukup".
 */
class AiReadiness
{
    /** @return array<string, array{0:string, 1:Closure():int}> metrik → [label, penghitung] */
    private function counters(): array
    {
        $finished = fn () => DB::table('enrollments')->whereIn('status', ['completed', 'failed']);

        return [
            'finished_enrollments' => ['Enrollment selesai (lulus atau tidak lulus)', fn () => $finished()->count()],
            'failed_enrollments' => ['Enrollment tidak lulus', fn () => DB::table('enrollments')->where('status', 'failed')->count()],
            'finished_classes' => ['Kelas selesai dengan hasil peserta', fn () => $finished()->distinct()->count('schedule_id')],
            'members_with_history' => ['Member dengan riwayat pelatihan', fn () => DB::table('enrollments')->whereNotNull('member_id')
                ->whereIn('status', ['enrolled', 'attending', 'completed', 'failed'])->distinct()->count('member_id')],
            'repeat_members' => ['Member yang ikut 2 pelatihan atau lebih', fn () => DB::query()->fromSub(
                DB::table('enrollments')->whereNotNull('member_id')->where('status', '!=', 'dropped')
                    ->groupBy('member_id')->havingRaw('COUNT(*) >= 2')->select('member_id'), 'r')->count()],
            'survey_comments' => ['Komentar survei evaluasi pelatihan', fn () => Schema::hasTable('survey_responses') ? AiFeedbackService::commentCount() : 0],
            'published_lessons' => ['Lesson terbit yang berisi materi teks', fn () => DB::table('lessons')->where('is_published', true)
                ->whereNotNull('body')->where('body', '!=', '')->count()],
            'organizations_with_targets' => ['Organisasi dengan target kompetensi', fn () => DB::table('competency_targets')->distinct()->count('organization_id')],
            'competency_assessments' => ['Penilaian kompetensi tervalidasi', fn () => DB::table('competency_assessments')->where('status', 'validated')->count()],
            'registration_months' => ['Bulan dengan data pendaftaran', fn () => DB::table('registrations')->pluck('created_at')
                ->map(fn ($d) => substr((string) $d, 0, 7))->unique()->count()],
        ];
    }

    /**
     * @return array{key:string,label:string,ready:bool,checks:list<array{metric:string,label:string,actual:int,required:int,ok:bool}>}
     */
    public function report(string $useCase): array
    {
        $uc = AiUseCases::get($useCase);
        $counters = $this->counters();
        $checks = [];
        foreach ($uc['readiness'] as $metric => $required) {
            [$label, $count] = $counters[$metric] ?? [$metric, fn () => 0];
            $actual = (int) $count();
            $checks[] = ['metric' => $metric, 'label' => $label, 'actual' => $actual, 'required' => $required, 'ok' => $actual >= $required];
        }

        return [
            'key' => $useCase,
            'label' => $uc['label'],
            'ready' => collect($checks)->every(fn ($c) => $c['ok']),
            'checks' => $checks,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map($this->report(...), AiUseCases::keys());
    }
}
