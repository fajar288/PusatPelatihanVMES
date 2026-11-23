<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataDeletionRequest;
use App\Services\DataDeletionService;
use App\Support\IdDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Permintaan penghapusan data pribadi oleh pemilik akun (portal /portal/privasi). */
class DataDeletionController extends Controller
{
    public function __construct(private readonly DataDeletionService $deletion) {}

    /** GET /me/data-deletion: permintaan terakhir, penghalang/peringatan saat ini, dan penjelasan kebijakan. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = $this->deletion->latestFor($user);
        $blockers = $this->deletion->blockers($user);

        return response()->json(['data' => [
            'request' => $latest ? $this->present($latest) : null,
            'canRequest' => ! ($latest?->isActive() ?? false),
            'blockers' => $blockers,
            'warnings' => $this->deletion->warnings($user),
            'deadlineDays' => $this->deletion->deadlineDays(),
            'policy' => DataDeletionService::POLICY,
        ]]);
    }

    /** POST /me/data-deletion {email|password, reason?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'required_without:password', 'string', 'max:255'],
            'password' => ['nullable', 'required_without:email', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [], ['email' => 'email konfirmasi', 'password' => 'kata sandi', 'reason' => 'alasan']);

        $req = $this->deletion->request($request->user(), $data);

        return response()->json(['data' => $this->present($req)], 201);
    }

    /** POST /me/data-deletion/cancel */
    public function cancel(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->deletion->cancel($request->user()))]);
    }

    /** @return array<string, mixed> */
    private function present(DataDeletionRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'statusLabel' => $r->statusLabel(),
            'autoRejected' => $r->auto_rejected,
            'reason' => $r->reason,
            'adminNote' => $r->admin_note,
            'blockers' => $r->blockers ?? [],
            'requestedAt' => $r->requested_at?->toIso8601String(),
            'requestedLabel' => $r->requested_at ? IdDate::long($r->requested_at) : null,
            'dueLabel' => $r->due_at ? IdDate::long($r->due_at) : null,
            'processedLabel' => $r->processed_at ? IdDate::long($r->processed_at) : null,
            'cancellable' => $r->status === 'pending',
        ];
    }
}
