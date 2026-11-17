<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiInsight;
use App\Models\Organization;
use App\Services\AiInsightAccess;
use App\Services\AiInsightService;
use App\Services\AiRecommendationService;
use App\Services\CorporateAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Keluaran AI untuk pengguna: rekomendasi training, umpan balik (membantu / tidak), dan pencatatan klik. */
class AiInsightController extends Controller
{
    /** GET /me/recommendations: rekomendasi untuk member/alumni yang login (null bila fitur belum tersedia). */
    public function myRecommendations(Request $request, AiRecommendationService $recommendations): JsonResponse
    {
        $member = $request->user()->member;

        return response()->json(['data' => [
            'status' => $recommendations->status(),
            'recommendations' => $member ? $recommendations->forMember($member, $request->user()) : null,
        ]]);
    }

    /** GET /corporate/organizations/{org}/recommendations: rekomendasi per posisi (angka agregat, batas unit pengguna). */
    public function corporateRecommendations(Request $request, Organization $organization, AiRecommendationService $recommendations, CorporateAccess $access): JsonResponse
    {
        Gate::authorize('viewCorporate', $organization);
        $cu = $access->membership($request->user(), $organization);
        $cu->setRelation('organization', $organization);

        return response()->json(['data' => [
            'status' => $recommendations->status(),
            'recommendations' => $recommendations->forCorporate($cu, $request->user()),
        ]]);
    }

    /** POST /ai-insights/{id}/click {package}: catat klik penerima rekomendasi, kembalikan slug program. */
    public function click(Request $request, int $id, AiInsightAccess $access, AiRecommendationService $recommendations): JsonResponse
    {
        $data = $request->validate(['package' => ['required', 'string', 'max:255']]);
        $insight = AiInsight::query()->where('use_case', AiRecommendationService::USE_CASE)->findOrFail($id);
        abort_unless(AiInsight::query()->visible()->whereKey($id)->exists() && $access->isRecipient($request->user(), $insight), 404);
        $slug = $recommendations->click($insight, $data['package'], $request->user());
        abort_if($slug === null, 404);

        return response()->json(['data' => ['package' => $slug]]);
    }

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
