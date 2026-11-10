<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_register_creates_user_member_and_token(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi', 'email' => 'budi@example.com', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
            'phone' => '0812345678', 'company' => 'PT Contoh', 'consent' => true,
        ])->assertCreated()->assertJsonStructure(['token', 'user' => ['member' => ['memberNumber']]]);

        $this->assertMatchesRegularExpression('/^MBR-\d{4}-000001$/', $res->json('user.member.memberNumber'));
        $this->assertSame('student', $res->json('user.role'));

        $this->withToken($res->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'budi@example.com');
    }

    public function test_register_rejects_duplicate_email_and_weak_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'ada@example.com', 'password' => '123', 'password_confirmation' => '123', 'phone' => '1', 'consent' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_logout(): void
    {
        $user = User::factory()->create(['password' => 'rahasia123']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'salah'])->assertUnprocessable();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'rahasia123'])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.member.status', 'registered');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        // guard menyimpan user antar-request dalam satu tes → reset agar request berikut benar-benar tanpa auth
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_forgot_password_sends_frontend_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'tidak-ada@example.com'])->assertOk();
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, config('services.frontend.url').'/reset-password?token=');
        });
    }

    public function test_profile_update(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me', ['name' => 'Nama Baru', 'city' => 'Batam', 'communication_pref' => ['email' => true, 'whatsapp' => false]])
            ->assertOk()->assertJsonPath('data.name', 'Nama Baru')->assertJsonPath('data.member.city', 'Batam')->assertJsonPath('data.member.communicationPref.whatsapp', false);
    }
}
