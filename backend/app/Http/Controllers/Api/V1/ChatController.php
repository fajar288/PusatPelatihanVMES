<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Chatbot informasi publik — GET /chat/status, POST /chat, POST /chat/feedback. */
class ChatController extends Controller
{
    public function __construct(private ChatbotService $chatbot) {}

    /** Widget hanya ditampilkan bila chatbot aktif. */
    public function status(): JsonResponse
    {
        return response()->json(['enabled' => $this->chatbot->enabled()]);
    }

    public function send(Request $request): JsonResponse
    {
        abort_unless($this->chatbot->enabled(), 503, 'Asisten virtual belum diaktifkan.');

        $max = (int) config('chatbot.max_chars');
        $data = $request->validate([
            'conversationId' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'messages' => ['required', 'array', 'min:1', 'max:'.((int) config('chatbot.max_turns') * 2)],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:'.max($max, 4000)],
        ]);

        // Giliran harus berselang-seling dan diawali/diakhiri pesan pengunjung.
        $messages = array_values(array_map(fn (array $m) => ['role' => $m['role'], 'content' => trim($m['content'])], $data['messages']));
        foreach ($messages as $i => $m) {
            if ($m['role'] !== ($i % 2 === 0 ? 'user' : 'assistant') || $m['content'] === '') {
                abort(422, 'Format percakapan tidak valid.');
            }
        }
        $last = end($messages);
        if ($last['role'] !== 'user') {
            abort(422, 'Pesan terakhir harus dari pengunjung.');
        }
        if (mb_strlen($last['content']) > $max) {
            abort(422, "Pesan terlalu panjang (maks. {$max} karakter).");
        }

        $result = $this->chatbot->ask($messages, $data['conversationId'], $request->ip());

        return response()->json(['data' => $result], $result['status'] === 'limited' ? 429 : 200);
    }

    public function feedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'conversationId' => ['required', 'string', 'max:64'],
            'value' => ['required', Rule::in(['up', 'down'])],
        ]);
        abort_unless($this->chatbot->feedback($data['id'], $data['conversationId'], $data['value']), 404);

        return response()->json(['ok' => true]);
    }
}
