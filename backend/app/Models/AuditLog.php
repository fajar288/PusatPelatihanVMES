<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/** Jejak audit: siapa, aksi apa, pada model mana, sebelum/sesudah (PRD §9). */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    /** Jejak audit tidak bisa diubah/dihapus lewat aplikasi (Roadmap 5 §13 "Immutable activity trail", §20.20). */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Audit log tidak bisa dihapus.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
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
        // aksi yang dipicu lewat API Publik ditandai klien API-nya (Roadmap 5E, §7.1 "Audit log")
        $client = $request?->attributes->get('api_client');

        return static::query()->create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? ($client instanceof ApiClient ? 'API: '.$client->name : 'system'),
            'api_client_id' => $client instanceof ApiClient ? $client->id : null,
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
