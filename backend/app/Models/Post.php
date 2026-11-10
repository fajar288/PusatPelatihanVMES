<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    /** type → label kategori & slug halaman (sama dengan website lama). */
    public const TYPES = [
        'tutorial' => ['label' => 'Tutorial', 'slug' => 'tutorial'],
        'news' => ['label' => 'Berita', 'slug' => 'berita'],
        'activity' => ['label' => 'Kegiatan', 'slug' => 'kegiatan'],
        'documentation' => ['label' => 'Dokumentasi', 'slug' => 'dokumentasi'],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tags' => 'array', 'published_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published')
            ->where(fn (Builder $w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at');
    }

    public function scopeOfType(Builder $q, ?string $type): Builder
    {
        if (! $type) {
            return $q;
        }
        // terima type internal ("news") maupun slug halaman ("berita")
        foreach (self::TYPES as $key => $meta) {
            if ($type === $key || $type === $meta['slug']) {
                return $q->where('type', $key);
            }
        }

        return $q->whereRaw('1 = 0');
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.mb_strtolower($term).'%';

        return $q->where(fn (Builder $w) => $w
            ->whereRaw('LOWER(title) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(excerpt, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(body, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(CAST(tags AS TEXT), \'\')) LIKE ?', [$like]));
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucfirst((string) $this->type);
    }

    public function typeSlug(): string
    {
        return self::TYPES[$this->type]['slug'] ?? (string) $this->type;
    }
}
