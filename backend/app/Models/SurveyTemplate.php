<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Template survei evaluasi pasca-kelas: daftar pertanyaan skala 1-5 dan komentar. */
class SurveyTemplate extends Model
{
    public const TYPES = ['scale' => 'Skala 1-5', 'comment' => 'Komentar'];

    protected $fillable = ['name', 'description', 'questions', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** Pertanyaan bawaan (dipakai bila belum ada template). */
    public const DEFAULT_QUESTIONS = [
        ['key' => 'overall', 'type' => 'scale', 'label' => 'Secara keseluruhan, seberapa puas Anda dengan pelatihan ini?', 'required' => true],
        ['key' => 'material', 'type' => 'scale', 'label' => 'Materi relevan dan mudah dipahami', 'required' => true],
        ['key' => 'trainer', 'type' => 'scale', 'label' => 'Trainer menguasai materi dan menjelaskan dengan jelas', 'required' => true],
        ['key' => 'practice', 'type' => 'scale', 'label' => 'Porsi praktik cukup untuk menguasai keterampilan', 'required' => true],
        ['key' => 'facility', 'type' => 'scale', 'label' => 'Fasilitas, peralatan, atau platform online mendukung', 'required' => true],
        ['key' => 'liked', 'type' => 'comment', 'label' => 'Apa yang paling bermanfaat dari pelatihan ini?', 'required' => false],
        ['key' => 'improve', 'type' => 'comment', 'label' => 'Apa yang perlu kami perbaiki?', 'required' => false],
    ];

    public static function default(): self
    {
        return self::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first()
            ?? self::query()->create(['name' => 'Survei evaluasi pelatihan (bawaan)', 'questions' => self::DEFAULT_QUESTIONS, 'is_default' => true, 'is_active' => true]);
    }

    /** @return list<array{key: string, type: string, label: string, required: bool}> */
    public function questionList(): array
    {
        return array_values(array_map(fn ($q) => [
            'key' => (string) $q['key'], 'type' => (string) $q['type'], 'label' => (string) $q['label'], 'required' => (bool) ($q['required'] ?? false),
        ], (array) $this->questions));
    }
}
