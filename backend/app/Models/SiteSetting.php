<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** Ambil nilai pengaturan (cache 10 menit; di-flush saat disimpan). */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("settings.$key", 600, fn () => static::query()->where('key', $key)->value('value')) ?? $default;
    }

    public static function set(string $key, mixed $value, ?string $group = null): static
    {
        $row = static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        Cache::forget("settings.$key");

        return $row;
    }

    /** @return array<string, mixed> */
    public static function allValues(): array
    {
        return static::query()->pluck('value', 'key')->all();
    }
}
