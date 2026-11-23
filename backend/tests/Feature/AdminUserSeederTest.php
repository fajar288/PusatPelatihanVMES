<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/** Super admin awal di produksi: wajib email & sandi kuat dari .env, dan sandi akun yang ada tidak pernah ditimpa. */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("$key=$value");
    }

    protected function tearDown(): void
    {
        $this->setEnv('ADMIN_EMAIL', null);
        $this->setEnv('ADMIN_PASSWORD', null);
        parent::tearDown();
    }

    public function test_production_refuses_missing_or_weak_password(): void
    {
        $this->app['env'] = 'production';
        foreach ([null, 'password', 'pendek123'] as $pw) {
            $this->setEnv('ADMIN_EMAIL', 'kepala@pusatpelatihan.id');
            $this->setEnv('ADMIN_PASSWORD', $pw);
            try {
                app(AdminUserSeeder::class)->run();
                $this->fail('Seeder harus menolak sandi: '.var_export($pw, true));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ADMIN_PASSWORD', $e->getMessage());
            }
        }
        $this->assertSame(0, User::query()->count());
    }

    public function test_production_creates_once_and_never_overwrites_password(): void
    {
        $this->app['env'] = 'production';
        $this->setEnv('ADMIN_EMAIL', 'kepala@pusatpelatihan.id');
        $this->setEnv('ADMIN_PASSWORD', 'Sandi-Kuat-Sekali-2026');
        app(AdminUserSeeder::class)->run();
        $admin = User::query()->where('email', 'kepala@pusatpelatihan.id')->firstOrFail();
        $this->assertTrue(Hash::check('Sandi-Kuat-Sekali-2026', $admin->password));
        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->role);

        // admin mengganti sandi di panel, lalu seeder dijalankan ulang dengan .env lama
        $admin->forceFill(['password' => Hash::make('Sandi-Baru-Dari-Panel'), 'role' => User::ROLE_ADMINISTRATOR])->save();
        app(AdminUserSeeder::class)->run();
        $admin->refresh();
        $this->assertTrue(Hash::check('Sandi-Baru-Dari-Panel', $admin->password));
        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->role);
    }

    public function test_local_development_keeps_simple_defaults(): void
    {
        $this->setEnv('ADMIN_EMAIL', null);
        $this->setEnv('ADMIN_PASSWORD', null);
        app(AdminUserSeeder::class)->run();
        $this->assertTrue(Hash::check('password', User::query()->where('email', 'admin@pusatpelatihan.id')->value('password')));
    }
}
