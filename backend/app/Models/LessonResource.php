<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Berkas/tautan pendukung lesson (e-book PDF/PPT/DOC/XLS, video file, dll.) dengan izin unduh. */
class LessonResource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['allow_download' => 'boolean'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** Jenis tampilan di frontend: pdf|office|image|video|audio|file|link. */
    public function kind(): string
    {
        if ($this->type === 'link') {
            return 'link';
        }
        $mime = (string) $this->mime;
        $ext = strtolower(pathinfo((string) ($this->file_name ?? $this->file_path), PATHINFO_EXTENSION));

        return match (true) {
            $mime === 'application/pdf' || $ext === 'pdf' => 'pdf',
            in_array($ext, ['doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'], true) => 'office',
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm'], true) => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'file',
        };
    }
}
