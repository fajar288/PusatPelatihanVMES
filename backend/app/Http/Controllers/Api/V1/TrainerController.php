<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTrainer;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerClassResource;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\Trainer;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Portal trainer (PRD §5.7): kelas yang ditugaskan, peserta, kehadiran, profil. Butuh profil `trainers.user_id` = user login. */
class TrainerController extends Controller
{
    use ResolvesTrainer;

    /** GET /trainer/profile */
    public function profile(Request $request): JsonResponse
    {
        $t = $this->trainer($request);

        return response()->json(['data' => $this->profileArray($t)]);
    }

    /** PATCH /trainer/profile */
    public function updateProfile(Request $request): JsonResponse
    {
        $t = $this->trainer($request);
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'], 'title' => ['nullable', 'string', 'max:255'], 'expertise' => ['nullable', 'array'], 'expertise.*' => ['string', 'max:100'],
            'education' => ['nullable', 'string', 'max:2000'], 'experience' => ['nullable', 'string', 'max:4000'], 'certifications' => ['nullable', 'array'], 'certifications.*' => ['string', 'max:150'],
            'portfolio' => ['nullable', 'string', 'max:2000'], 'bio' => ['nullable', 'string', 'max:2000'],
        ]);
        $before = $t->only(array_keys($data));
        $t->update($data);
        AuditLog::record('trainer.profile_updated', $t, $before, $data);

        return response()->json(['data' => $this->profileArray($t->refresh())]);
    }

    /** GET /trainer/classes — kelas yang ditugaskan (mendatang dulu). */
    public function classes(Request $request): AnonymousResourceCollection
    {
        $t = $this->trainer($request);
        $classes = $t->schedules()->with(['package', 'sessions', 'trainers'])->withCount('enrollments')->orderByDesc('start_date')->get();

        return TrainerClassResource::collection($classes);
    }

    /** GET /trainer/classes/{code} — peserta, sesi, matriks kehadiran. */
    public function show(Request $request, string $code): TrainerClassResource
    {
        $t = $this->trainer($request);
        $s = $this->classOf($t, $code)->load(['package', 'sessions.attendances', 'enrollments.attendances', 'trainers'])->loadCount('enrollments');

        return new TrainerClassResource($s);
    }

    /** POST /trainer/classes/{code}/sessions/{no}/attendance — tandai kehadiran massal. body: {marks:[{enrollment_id,status,note?}]} */
    public function attendance(Request $request, string $code, int $no): JsonResponse
    {
        $t = $this->trainer($request);
        $s = $this->classOf($t, $code);
        $session = ClassSession::query()->where('schedule_id', $s->id)->where('session_no', $no)->firstOrFail();
        $data = $request->validate([
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.enrollment_id' => ['required', 'integer', Rule::exists('enrollments', 'id')->where('schedule_id', $s->id)],
            'marks.*.status' => ['required', Rule::in(Attendance::STATUSES)],
            'marks.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        foreach ($data['marks'] as $m) {
            Attendance::query()->updateOrCreate(
                ['session_id' => $session->id, 'enrollment_id' => $m['enrollment_id']],
                ['status' => $m['status'], 'note' => $m['note'] ?? null, 'checked_at' => now(), 'checked_by' => $request->user()->id],
            );
        }
        AuditLog::record('attendance.marked', $session, null, ['class' => $s->code, 'session' => $no, 'count' => count($data['marks'])]);

        return response()->json(['ok' => true, 'marked' => count($data['marks'])]);
    }

    /** @return array<string, mixed> */
    private function profileArray(Trainer $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'title' => $t->title, 'email' => $t->email, 'phone' => $t->phone, 'photo' => Media::url($t->photo),
            'expertise' => $t->expertise ?? [], 'education' => $t->education, 'experience' => $t->experience, 'certifications' => $t->certifications ?? [],
            'portfolio' => $t->portfolio, 'bio' => $t->bio, 'isAssessor' => $t->is_assessor,
        ];
    }
}
