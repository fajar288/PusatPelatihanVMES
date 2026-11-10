<?php

namespace App\Policies;

use App\Models\Registration;
use App\Models\User;

class RegistrationPolicy
{
    /** Peran yang mengelola pendaftaran. */
    private const MANAGERS = [User::ROLE_SUPER_ADMIN, User::ROLE_ADMINISTRATOR, User::ROLE_MANAGEMENT, User::ROLE_MARKETING, User::ROLE_TRAINING_COORDINATOR, User::ROLE_FINANCE];

    public function viewAny(User $user): bool
    {
        return $user->hasRole(...self::MANAGERS);
    }

    public function view(User $user, Registration $registration): bool
    {
        return $this->viewAny($user) || $registration->user_id === $user->id || $registration->email === $user->email;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Registration $registration): bool
    {
        return $user->hasRole(...self::MANAGERS);
    }

    /** Verifikasi pembayaran: finance & admin. */
    public function verifyPayment(User $user, Registration $registration): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMINISTRATOR, User::ROLE_FINANCE);
    }

    public function cancel(User $user, Registration $registration): bool
    {
        return ($registration->user_id === $user->id || $registration->email === $user->email) && $registration->status->cancellableByOwner();
    }

    public function delete(User $user, Registration $registration): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMINISTRATOR);
    }
}
