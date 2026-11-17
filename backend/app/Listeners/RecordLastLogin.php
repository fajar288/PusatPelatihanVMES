<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/** Catat waktu login terakhir untuk login berbasis sesi (panel admin). Login API mengisinya di AuthController. */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        if ($event->user instanceof User && $event->user->isActive()) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        }
    }
}
