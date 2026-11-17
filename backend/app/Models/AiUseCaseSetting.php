<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Status jeda admin per use case AI. Admin hanya bisa mematikan; menyalakan tetap lewat flag env. */
class AiUseCaseSetting extends Model
{
    protected $primaryKey = 'use_case';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['use_case', 'paused', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['paused' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
