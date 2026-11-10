<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

/** Tautan reset sandi ke halaman frontend Next.js (bukan route web Laravel). */
class ResetPasswordNotification extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        $base = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');

        return "$base/reset-password?token={$this->token}&email=".rawurlencode($notifiable->getEmailForPasswordReset());
    }
}
