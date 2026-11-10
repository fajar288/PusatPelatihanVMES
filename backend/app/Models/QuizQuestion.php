<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Soal kuis/pre-test/post-test. */
class QuizQuestion extends Model
{
    public const TYPES = ['single' => 'Pilihan ganda (1 jawaban)', 'multiple' => 'Pilihan ganda (banyak jawaban)', 'true_false' => 'Benar / salah', 'short' => 'Isian singkat'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array', 'answer' => 'array', 'points' => 'decimal:2'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** Opsi efektif (true_false memakai opsi tetap). @return array<int, array{key:string,text:string}> */
    public function optionList(): array
    {
        if ($this->type === 'true_false') {
            return [['key' => 'true', 'text' => 'Benar'], ['key' => 'false', 'text' => 'Salah']];
        }

        return array_values(array_filter($this->options ?? [], fn ($o) => isset($o['key'], $o['text'])));
    }

    /** Nilai jawaban peserta (string|array) → [benar?, poin]. */
    public function grade(mixed $given): array
    {
        $key = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $this->answer ?? []);
        $given = is_array($given) ? $given : [$given];
        $given = array_values(array_filter(array_map(fn ($v) => mb_strtolower(trim((string) $v)), $given), fn ($v) => $v !== ''));
        $correct = match ($this->type) {
            'multiple' => $key !== [] && count(array_diff($key, $given)) === 0 && count(array_diff($given, $key)) === 0,
            'short' => $given !== [] && in_array($given[0], $key, true),
            default => $given !== [] && count($given) === 1 && in_array($given[0], $key, true),
        };

        return [$correct, $correct ? (float) $this->points : 0.0];
    }
}
