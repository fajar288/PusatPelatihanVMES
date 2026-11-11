<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan logbook yang ditautkan ke peserta (AC-08): status praktik & observasi individual. */
class LogbookEntry extends Model
{
    public const PRACTICE = ['completed' => 'Praktik selesai', 'partial' => 'Sebagian', 'not_done' => 'Belum praktik'];

    protected $guarded = [];

    public function logbook(): BelongsTo
    {
        return $this->belongsTo(TrainingLogbook::class, 'logbook_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
