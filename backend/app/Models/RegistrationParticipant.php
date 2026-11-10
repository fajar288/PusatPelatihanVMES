<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationParticipant extends Model
{
    protected $guarded = [];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
