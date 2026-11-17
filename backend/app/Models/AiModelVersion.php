<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu versi parameter model statistik use case AI (lihat migrasi ai_model_versions). */
class AiModelVersion extends Model
{
    protected $fillable = ['use_case', 'version', 'params', 'metrics', 'training_size', 'is_active', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['params' => 'array', 'metrics' => 'array', 'is_active' => 'boolean'];
    }

    public static function active(string $useCase): ?self
    {
        return self::query()->where('use_case', $useCase)->where('is_active', true)->latest('id')->first();
    }
}
