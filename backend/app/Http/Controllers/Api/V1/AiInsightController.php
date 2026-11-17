<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiInsight;
use App\Services\AiInsightAccess;
use App\Services\AiInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Umpan balik pengguna atas keluaran AI ("membantu" / "tidak membantu"), dipakai sebagai metrik kualitas. */
class AiInsightController extends Controller
{
    /** POST /ai-insights/{id}/feedback {helpful: bool, note?: string}. Insight yang tidak boleh dilihat → 404. */
    public function feedback(Request $request, int $id, AiInsightAccess $access, AiInsightService $insights): JsonResponse
    {
        $data = $request->validate(['helpful' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $insight = AiInsight::query()->findOrFail($id);
        abort_unless($access->canView($request->user(), $insight), 404);
        $fb = $insights->feedback($insight, $request->user(), (bool) $data['helpful'], $data['note'] ?? null);

        return response()->json(['data' => ['insightId' => $insight->id, 'helpful' => $fb->helpful]]);
    }
}
