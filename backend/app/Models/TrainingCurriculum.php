<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingCurriculum extends Model
{
    protected $table = 'training_curricula';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['topics' => 'array'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class, 'package_id');
    }
}
