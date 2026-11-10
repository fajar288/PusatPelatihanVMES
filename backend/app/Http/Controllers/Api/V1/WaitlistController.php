<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TrainingSchedule;
use App\Models\Waitlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/schedules/{code}/waitlist — daftar tunggu untuk kelas penuh (PRD §5.4). */
class WaitlistController extends Controller
{
    public function store(Request $request, TrainingSchedule $schedule): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'participants_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user('sanctum');

        $existing = Waitlist::query()->where('schedule_id', $schedule->id)->where('email', $data['email'])->where('status', 'waiting')->first();
        $row = $existing ?? Waitlist::query()->create($data + ['schedule_id' => $schedule->id, 'member_id' => $user?->member?->id, 'participants_count' => $data['participants_count'] ?? 1]);
        if (! $existing) {
            AuditLog::record('waitlist.joined', $row, null, ['schedule' => $schedule->code, 'email' => $row->email], $user);
        }

        $position = Waitlist::query()->where('schedule_id', $schedule->id)->where('status', 'waiting')->where('id', '<=', $row->id)->count();

        return response()->json([
            'data' => ['id' => $row->id, 'schedule' => $schedule->code, 'status' => $row->status, 'position' => $position],
            'message' => $existing ? 'Anda sudah ada di daftar tunggu kelas ini.' : "Anda masuk daftar tunggu kelas {$schedule->code} (urutan {$position}). Kami akan menghubungi bila ada kursi.",
        ], $existing ? 200 : 201);
    }
}
