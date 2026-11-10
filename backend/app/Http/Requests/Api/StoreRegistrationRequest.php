<?php

namespace App\Http\Requests\Api;

use App\Models\Registration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/v1/registrations — pendaftaran individu atau kolektif (PRD §5.5). */
class StoreRegistrationRequest extends FormRequest
{
    public function rules(): array
    {
        $corporate = $this->input('type') === Registration::TYPE_CORPORATE;

        return [
            'schedule' => ['required', 'string', 'exists:training_schedules,code'],
            'type' => ['required', Rule::in([Registration::TYPE_INDIVIDUAL, Registration::TYPE_CORPORATE])],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'company' => [$corporate ? 'required' : 'nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'participants_count' => [$corporate ? 'required' : 'nullable', 'integer', 'min:1', 'max:500'],
            'participants' => ['nullable', 'array', 'max:500'],
            'participants.*.name' => ['required_with:participants', 'string', 'max:255'],
            'participants.*.email' => ['nullable', 'email', 'max:255'],
            'participants.*.phone' => ['nullable', 'string', 'max:30'],
            'participants.*.position' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:5'],
            'documents.*' => ['string', 'max:255'],
            'consent' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return ['schedule' => 'jadwal', 'name' => 'nama', 'phone' => 'nomor WhatsApp', 'company' => 'perusahaan', 'consent' => 'persetujuan'];
    }
}
