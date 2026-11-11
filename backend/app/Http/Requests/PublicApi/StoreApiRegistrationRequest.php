<?php

namespace App\Http\Requests\PublicApi;

use App\Http\Requests\Api\StoreRegistrationRequest;

/** POST /api/public/v1/registrations — aturan sama dengan form website + `external_ref` (kunci idempoten dari sistem klien). */
class StoreApiRegistrationRequest extends StoreRegistrationRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'external_ref' => ['nullable', 'string', 'max:100'],
        ];
    }
}
