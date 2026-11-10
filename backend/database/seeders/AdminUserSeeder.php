<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun super admin awal untuk panel /admin.
 * Email & sandi dari env ADMIN_EMAIL / ADMIN_PASSWORD (default hanya untuk pengembangan lokal — GANTI di produksi).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@pusatpelatihan.id');
        $password = (string) env('ADMIN_PASSWORD', 'password');

        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => 'Administrator PPTI', 'password' => Hash::make($password), 'role' => User::ROLE_SUPER_ADMIN, 'email_verified_at' => now()],
        );
    }
}
