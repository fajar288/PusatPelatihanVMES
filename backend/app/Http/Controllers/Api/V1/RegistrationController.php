<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use App\Models\TrainingSchedule;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/registrations — pendaftaran publik (tamu atau member login). */
class RegistrationController extends Controller
{
    public function store(StoreRegistrationRequest $request, RegistrationService $service): JsonResponse
    {
        $data = $request->validated();
        $schedule = TrainingSchedule::query()->where('code', $data['schedule'])->firstOrFail();
        abort_unless(in_array($schedule->status, ['registration_open', 'confirmed'], true), 422, 'Pendaftaran untuk jadwal ini tidak dibuka.');

        $registration = $service->create($data, $schedule, $request->user('sanctum'), $data['participants'] ?? []);

        return (new RegistrationResource($registration->load('schedule.package', 'participants')))
            ->additional(['message' => "Pendaftaran diterima. Nomor registrasi Anda: {$registration->registration_number}"])
            ->response()->setStatusCode(201);
    }
}
