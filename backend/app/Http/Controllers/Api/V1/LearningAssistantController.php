<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\AiLearningAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asisten Belajar portal member: GET /me/assistant (status + course; ?brief=1 tanpa daftar course), GET /me/assistant/messages (riwayat),
 * POST /me/assistant/messages (pertanyaan). Service di-inject per metode (bukan konstruktor) agar tidak ikut tersimpan
 * bersama instance controller di route. Saat fitur mati, POST tetap 503 ai_unavailable tanpa membaca isi permintaan.
 */
class LearningAssistantController extends Controller
{
    public function status(Request $request, AiLearningAssistantService $assistant): JsonResponse
    {
        return response()->json(['data' => $assistant->status($request->user(), ! $request->boolean('brief'))]);
    }

    public function history(Request $request, AiLearningAssistantService $assistant): JsonResponse
    {
        $course = $request->filled('course') ? Course::query()->where('slug', (string) $request->query('course'))->firstOrFail() : null;
        $items = $assistant->history($request->user(), $course);
        $feedback = $assistant->feedbackMap($request->user(), $items);

        return response()->json(['data' => $items->map(fn ($i) => $assistant->present($i, $feedback[$i->id] ?? null))->all()]);
    }

    public function send(Request $request, AiLearningAssistantService $assistant): JsonResponse
    {
        if (! $assistant->available()) {
            return $this->error('ai_unavailable', 'Asisten Belajar belum tersedia.', 503);
        }
        $max = (int) config('ai.use_cases.lms_assistant.question_max', 1000);
        $data = $request->validate([
            'course' => ['required', 'string', 'max:200'],
            'question' => ['required', 'string', 'min:3', "max:$max"],
            'lessonId' => ['nullable', 'integer'],
        ], [], ['course' => 'course', 'question' => 'pertanyaan', 'lessonId' => 'lesson']);
        $course = Course::query()->where('slug', $data['course'])->firstOrFail();

        $r = $assistant->ask($request->user(), $course, $data['question'], isset($data['lessonId']) ? (int) $data['lessonId'] : null);

        return match ($r['status']) {
            'answered' => response()->json(['data' => $assistant->present($r['insight'])], 201, ['Cache-Control' => 'no-store']),
            'limit' => $this->error('ai_limit', $r['message'], 429),
            'failed' => $this->error('ai_failed', $r['message'], 503),
            default => $this->error('ai_unavailable', $r['message'] ?? 'Asisten Belajar belum tersedia.', 503),
        };
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, ['Cache-Control' => 'no-store']);
    }
}
