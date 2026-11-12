<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ParticipantInvitation;
use App\Services\ParticipantAssignmentService;
use App\Support\IdDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Undangan peserta (Roadmap 5B §20.4): GET /invitations/{token} (publik), POST /invitations/{token}/accept (login). */
class InvitationController extends Controller
{
    public function __construct(private ParticipantAssignmentService $assignments) {}

    public function show(string $token): JsonResponse
    {
        $inv = $this->assignments->findInvitation($token);

        return response()->json(['data' => $this->present($inv)]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['consent' => ['required', 'boolean']]);
        $seat = $this->assignments->acceptInvitation($token, $request->user(), (bool) $data['consent']);

        return response()->json(['data' => ['status' => $seat->status, 'request' => $seat->request->code, 'schedule' => $seat->request->schedule?->code]]);
    }

    /** Data minimal: tanpa token, tanpa data peserta lain. Email disamarkan agar tautan bocor tidak membuka email utuh. */
    private function present(ParticipantInvitation $inv): array
    {
        [$local, $domain] = array_pad(explode('@', $inv->email, 2), 2, '');
        $r = $inv->request;

        return [
            'status' => $inv->status,
            'usable' => $inv->isUsable(),
            'emailHint' => mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain,
            'name' => $inv->name,
            'organization' => $inv->organization->name(),
            'program' => $r->package?->title,
            'schedule' => $r->schedule ? ['code' => $r->schedule->code, 'start' => IdDate::long($r->schedule->start_date), 'end' => IdDate::long($r->schedule->end_date), 'city' => $r->schedule->city] : null,
            'targetDate' => IdDate::long($r->target_date),
            'expiresAt' => IdDate::long($inv->expires_at),
        ];
    }
}
