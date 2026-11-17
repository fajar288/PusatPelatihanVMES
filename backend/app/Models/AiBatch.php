<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu Message Batch yang dikirim ke Claude (lihat migrasi ai_batches). `items` hanya berisi ID referensi. */
class AiBatch extends Model
{
    protected $fillable = ['use_case', 'provider_id', 'status', 'items', 'request_count', 'succeeded', 'failed', 'error', 'submitted_at', 'ended_at'];

    protected function casts(): array
    {
        return ['items' => 'array', 'submitted_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
