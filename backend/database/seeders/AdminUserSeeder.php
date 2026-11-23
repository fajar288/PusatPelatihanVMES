<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Akun super admin awal untuk panel /admin.
 * Email & sandi dari env ADMIN_EMAIL / ADMIN_PASSWORD (default hanya untuk pengembangan lokal).
 *
 * Di produksi (APP_ENV=production) seeder ini menolak berjalan tanpa ADMIN_EMAIL dan ADMIN_PASSWORD yang kuat
 * (min. 12 karakter, bukan "password"), dan TIDAK menimpa sandi akun yang sudah ada. Dengan config:cache aktif,
 * env() bernilai kosong sehingga seeder ini juga menolak berjalan: jalankan `php artisan config:clear` dulu.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $production = app()->isProduction();
        $email = trim((string) env('ADMIN_EMAIL', $production ? '' : 'admin@pusatpelatihan.id'));
        $password = (string) env('ADMIN_PASSWORD', $production ? '' : 'password');

        if ($production) {
            if ($email === '' || mb_strlen($password) < 12 || strtolower($password) === 'password') {
                throw new RuntimeException('ADMIN_EMAIL dan ADMIN_PASSWORD (min. 12 karakter) wajib diisi di .env produksi sebelum membuat super admin. Bila config:cache aktif, jalankan php artisan config:clear dulu.');
            }
            $existing = User::query()->where('email', $email)->first();
            if ($existing) {
                // sandi akun yang sudah ada tidak ditimpa di produksi; cukup pastikan perannya
                $existing->forceFill(['role' => User::ROLE_SUPER_ADMIN])->save();

                return;
            }
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => 'Administrator PPTI', 'password' => Hash::make($password), 'role' => User::ROLE_SUPER_ADMIN, 'email_verified_at' => now()],
        );
    }
}
