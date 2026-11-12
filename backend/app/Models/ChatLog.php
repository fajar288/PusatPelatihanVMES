<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu tanya-jawab chatbot publik (lihat migrasi chat_logs). */
class ChatLog extends Model
{
    use Prunable;

    public const STATUSES = [
        'answered' => 'Terjawab',
        'unresolved' => 'Tidak terjawab',
        'refused' => 'Ditolak model',
        'error' => 'Gagal',
    ];

    public const REVIEWS = [
        'correct' => 'Tepat',
        'incorrect' => 'Keliru',
        'needs_info' => 'Data kurang',
    ];

    protected $guarded = [];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(max(1, (int) config('chatbot.log_retention_days'))));
    }
}
