<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Registration;
use App\Models\User;
use App\Services\RegistrationService;
use App\Enums\RegistrationStatus;
use App\Support\PrivateFiles;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pengerasan keamanan: header, pembatas laju per pengunjung asli, berkas pribadi di disk privat. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    public function test_security_headers_on_api_and_admin(): void
    {
        foreach (['/api/v1/health', '/admin/login'] as $url) {
            $this->get($url)->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $this->get('https://localhost/api/v1/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_login_is_limited_per_email_without_blocking_other_accounts(): void
    {
        User::factory()->create(['email' => 'korban@example.com', 'password' => 'rahasia123']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'korban@example.com', 'password' => 'salah'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'korban@example.com', 'password' => 'rahasia123'])->assertStatus(429);
        // akun lain dari IP yang sama tetap bisa mencoba
        User::factory()->create(['email' => 'lain@example.com', 'password' => 'rahasia123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'lain@example.com', 'password' => 'rahasia123'])->assertOk();
    }

    public function test_client_ip_from_frontend_is_trusted_only_with_shared_secret(): void
    {
        config(['services.frontend.proxy_secret' => 'rahasia-proxy']);
        $send = fn (string $ip, string $secret, int $n) => $this->withHeaders(['X-Client-IP' => $ip, 'X-Proxy-Secret' => $secret])
            ->postJson('/api/v1/auth/forgot-password', ['email' => "orang$n@example.com"]);

        // pengunjung A menghabiskan jatahnya (10/menit per IP) ...
        for ($i = 0; $i < 10; $i++) {
            $send('203.0.113.10', 'rahasia-proxy', $i)->assertOk();
        }
        $send('203.0.113.10', 'rahasia-proxy', 99)->assertStatus(429);
        // ... pengunjung B lewat server frontend yang sama tidak ikut terblokir
        $send('203.0.113.20', 'rahasia-proxy', 100)->assertOk();

        // secret salah: IP kiriman diabaikan, dihitung sebagai IP koneksi (127.0.0.1) sehingga tidak bisa mengakali batas
        for ($i = 0; $i < 10; $i++) {
            $send('198.51.100.'.$i, 'tebakan', 200 + $i)->assertOk();
        }
        $send('198.51.100.99', 'tebakan', 300)->assertStatus(429);
    }

    public function test_payment_proof_is_private_and_admin_gets_signed_url(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        $member = User::factory()->create(['email' => 'peserta@example.com']);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', [
            'schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Peserta', 'email' => 'peserta@example.com', 'phone' => '0812', 'consent' => true,
        ])->assertCreated();
        $reg = Registration::query()->firstOrFail();
        $svc = app(RegistrationService::class);
        $svc->transition($reg, RegistrationStatus::UnderReview);
        $svc->transition($reg, RegistrationStatus::WaitingPayment);
        $invoice = Invoice::query()->where('registration_id', $reg->id)->firstOrFail();

        $this->actingAs($member, 'sanctum')->post("/api/v1/me/invoices/{$invoice->invoice_number}/proof", ['proof' => UploadedFile::fake()->image('bukti.jpg')], ['Accept' => 'application/json'])->assertOk();
        $path = PaymentTransaction::query()->latest('id')->value('proof_path');

        Storage::disk(PrivateFiles::diskName())->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(PrivateFiles::diskName(), PrivateFiles::locateDisk($path));
        Carbon::setTestNow();
    }

    public function test_legacy_public_files_are_still_found_and_privatize_moves_them(): void
    {
        Storage::disk('public')->put('payments/INV-LAMA/bukti.jpg', 'lama');
        Storage::disk('public')->put('lms/submissions/7/tugas.pdf', '%PDF tugas');
        Storage::disk('public')->put('galleries/foto.jpg', 'publik');

        // berkas lama masih bisa dibuka lewat fallback
        $this->assertSame('public', PrivateFiles::locateDisk('payments/INV-LAMA/bukti.jpg'));

        $this->artisan('files:privatize', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('public')->assertExists('payments/INV-LAMA/bukti.jpg');

        $this->artisan('files:privatize')->expectsOutputToContain('2 berkas dipindah')->assertSuccessful();
        Storage::disk('public')->assertMissing('payments/INV-LAMA/bukti.jpg');
        Storage::disk('public')->assertMissing('lms/submissions/7/tugas.pdf');
        Storage::disk(PrivateFiles::diskName())->assertExists('payments/INV-LAMA/bukti.jpg');
        $this->assertSame('%PDF tugas', Storage::disk(PrivateFiles::diskName())->get('lms/submissions/7/tugas.pdf'));
        // gambar CMS tetap publik
        Storage::disk('public')->assertExists('galleries/foto.jpg');
        $this->assertSame(PrivateFiles::diskName(), PrivateFiles::locateDisk('payments/INV-LAMA/bukti.jpg'));
    }
}
