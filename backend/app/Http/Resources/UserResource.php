<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $m = $this->member;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'roles' => $this->roleSlugs(),
            'isAdmin' => $this->isAdmin(),
            'member' => $m ? [
                'memberNumber' => $m->member_number,
                'status' => $m->status,
                'phone' => $m->phone,
                'birthDate' => $m->birth_date?->toDateString(),
                'gender' => $m->gender,
                'company' => $m->company,
                'position' => $m->position,
                'education' => $m->education,
                'address' => $m->address,
                'city' => $m->city,
                'photo' => Media::url($m->photo),
                'communicationPref' => $m->communication_pref ?? ['email' => true, 'whatsapp' => true],
                'since' => $m->created_at?->toDateString(),
            ] : null,
        ];
    }
}
