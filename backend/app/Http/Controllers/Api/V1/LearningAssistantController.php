<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LearningAssistantService;
use Illuminate\Http\JsonResponse;

/**
 * Asisten Belajar portal member (kerangka 5G): GET /me/assistant (status), POST /me/assistant/messages (selalu 503).
 * Isi permintaan sengaja tidak dibaca, divalidasi, maupun disimpan - tidak ada data peserta yang diproses.
 */
class LearningAssistantController extends Controller
{
    public function __construct(private LearningAssistantService $assistant) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->assistant->status()]);
    }

    public function send(): JsonResponse
    {
        return response()->json($this->assistant->unavailableError(), 503, ['Cache-Control' => 'no-store']);
    }
}
