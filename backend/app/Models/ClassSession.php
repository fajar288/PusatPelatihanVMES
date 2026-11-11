<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Pertemuan/sesi dalam satu kelas (dasar kehadiran & logbook). */
class ClassSession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'session_id');
    }

    public function logbook(): HasOne
    {
        return $this->hasOne(TrainingLogbook::class, 'session_id');
    }

    public function label(): string
    {
        return "Sesi {$this->session_no} — ".$this->date->locale('id')->translatedFormat('j M Y').($this->topic ? " · {$this->topic}" : '');
    }
}
