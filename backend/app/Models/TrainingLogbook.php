<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Logbook per pertemuan (PRD §5.9): topik, aktivitas, kompetensi, praktik, observasi, kendala, catatan, dokumentasi. */
class TrainingLogbook extends Model
{
    public const STATUSES = ['draft' => 'Draf', 'validated' => 'Tervalidasi'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'competencies' => 'array', 'documentation' => 'array', 'validated_at' => 'datetime', 'is_locked' => 'boolean'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'session_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LogbookEntry::class, 'logbook_id');
    }

    public function isValidated(): bool
    {
        return $this->status === 'validated';
    }

    public function label(): string
    {
        return $this->date->locale('id')->translatedFormat('j M Y').' - '.$this->topic;
    }
}
