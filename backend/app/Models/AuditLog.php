<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Jejak audit: siapa, aksi apa, pada model mana, sebelum/sesudah (PRD §9). */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  array<string, mixed>|null  $before */
    public static function record(string $action, ?Model $model = null, ?array $before = null, ?array $after = null, ?User $actor = null): self
    {
        $actor ??= auth()->user();
        $request = request();

        return static::query()->create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'system',
            'action' => $action,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
