<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lesson (PRD §5.8). Tipe: text | document | video | link | quiz | assignment.
 * Konfigurasi per tipe di kolom JSON `settings` (lihat SETTINGS_DEFAULT).
 */
class Lesson extends Model
{
    public const TYPES = ['text' => 'Materi teks', 'document' => 'Dokumen / e-book', 'video' => 'Video', 'link' => 'Tautan eksternal', 'quiz' => 'Kuis / tes', 'assignment' => 'Tugas'];

    public const QUIZ_KINDS = ['quiz' => 'Kuis', 'pre_test' => 'Pre-test', 'post_test' => 'Post-test'];

    public const SETTINGS_DEFAULT = [
        'video' => ['video_url' => null, 'provider' => null, 'duration_seconds' => null, 'thumbnail' => null, 'min_watch_pct' => 80],
        'quiz' => ['kind' => 'quiz', 'time_limit_minutes' => null, 'passing_score' => 70, 'max_attempts' => 3, 'shuffle_questions' => false, 'show_answers' => true],
        'assignment' => ['due_at' => null, 'max_score' => 100, 'allow_file' => true, 'allow_text' => true],
        'link' => ['url' => null],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_required' => 'boolean', 'is_published' => 'boolean', 'available_from' => 'date'];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class)->orderBy('sort_order')->orderBy('id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true);
    }

    /** Pengaturan efektif (default per tipe + override). @return array<string, mixed> */
    public function config(): array
    {
        return array_merge(self::SETTINGS_DEFAULT[$this->type] ?? [], array_filter($this->settings ?? [], fn ($v) => $v !== null && $v !== ''));
    }

    public function isAvailableNow(): bool
    {
        return $this->is_published && ($this->available_from === null || ! $this->available_from->isFuture());
    }

    /** Penyedia video dari URL (youtube|vimeo|file). */
    public static function videoProvider(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        if (preg_match('~(youtube\.com|youtu\.be)~i', $url)) {
            return 'youtube';
        }
        if (preg_match('~vimeo\.com~i', $url)) {
            return 'vimeo';
        }

        return 'file';
    }

    /** URL embed untuk YouTube/Vimeo; untuk file dikembalikan apa adanya. */
    public static function videoEmbedUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            return 'https://www.youtube.com/embed/'.$m[1].'?rel=0';
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return $url;
    }
}
