<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Trainer;
use App\Models\TrainingSchedule;
use Illuminate\Http\Request;

/** Resolusi profil trainer aktif dari user login & kelas yang ditugaskan (portal /trainer). */
trait ResolvesTrainer
{
    protected function trainer(Request $request): Trainer
    {
        $trainer = Trainer::query()->where('user_id', $request->user()->id)->first();
        abort_unless($trainer && $trainer->is_active, 403, 'Akun ini tidak terhubung dengan profil trainer aktif.');

        return $trainer;
    }

    protected function classOf(Trainer $trainer, string $code): TrainingSchedule
    {
        $schedule = TrainingSchedule::query()->where('code', $code)->firstOrFail();
        abort_unless($trainer->schedules()->whereKey($schedule->id)->exists(), 403, 'Kelas ini tidak ditugaskan kepada Anda.');

        return $schedule;
    }
}
