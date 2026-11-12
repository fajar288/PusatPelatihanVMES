<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\PaymentAccount;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\RegistrationStatusChanged;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Rekening tujuan pembayaran manual: admin, API portal, email tagihan. */
class PaymentAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
    }

    private function accounts(): void
    {
        PaymentAccount::create(['type' => 'virtual_account', 'bank_name' => 'BCA', 'account_number' => '8808 1234 5678', 'account_name' => 'PT. PPTI - VMES', 'instructions' => 'm-BCA → m-Transfer → BCA Virtual Account', 'sort' => 2]);
        PaymentAccount::create(['bank_name' => 'Mandiri', 'account_number' => '123-00-0987654-3', 'account_name' => 'PT. PPTI - VMES', 'sort' => 1]);
        PaymentAccount::create(['bank_name' => 'BNI', 'account_number' => '999', 'account_name' => 'Lama', 'is_active' => false]);
    }

    public function test_member_sees_only_active_accounts_in_order(): void
    {
        $this->accounts();
        $this->getJson('/api/v1/me/payment-accounts')->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/me/payment-accounts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.bank', 'Mandiri')
            ->assertJsonPath('data.0.type', 'bank_transfer')
            ->assertJsonPath('data.1.bank', 'BCA')
            ->assertJsonPath('data.1.typeLabel', 'Virtual Account')
            ->assertJsonPath('data.1.number', '8808 1234 5678')
            ->assertJsonPath('data.1.instructions', 'm-BCA → m-Transfer → BCA Virtual Account');
    }

    public function test_invoice_email_lists_active_accounts(): void
    {
        $this->accounts();
        $reg = $this->waitingPaymentRegistration();
        $html = (string) (new RegistrationStatusChanged($reg, RegistrationStatus::UnderReview))->toMail($reg)->render();

        $this->assertStringContainsString('Mandiri 123-00-0987654-3 a.n. PT. PPTI - VMES', $html);
        $this->assertStringContainsString('BCA (Virtual Account) 8808 1234 5678', $html);
        $this->assertStringNotContainsString('BNI', $html);
    }

    public function test_invoice_email_falls_back_without_accounts(): void
    {
        $reg = $this->waitingPaymentRegistration();
        $html = (string) (new RegistrationStatusChanged($reg, RegistrationStatus::UnderReview))->toMail($reg)->render();

        $this->assertStringContainsString('ke rekening resmi PT. PPTI - VMES Training Center', $html);
    }

    public function test_finance_can_manage_accounts_but_marketing_cannot(): void
    {
        $this->accounts();
        $finance = User::factory()->create(['role' => User::ROLE_FINANCE]);
        $this->actingAs($finance)->get('/admin/payment-accounts')->assertOk()->assertSee('Mandiri')->assertSee('Rekening pembayaran');

        $marketing = User::factory()->create(['role' => User::ROLE_MARKETING]);
        $this->actingAs($marketing)->get('/admin/payment-accounts')->assertForbidden();
    }

    private function waitingPaymentRegistration(): Registration
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        Notification::fake();
        $this->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Rizky', 'email' => 'rizky@example.com', 'phone' => '0812', 'consent' => true])->assertCreated();
        $reg = Registration::query()->firstOrFail();
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $service = app(RegistrationService::class);
        $service->transition($reg, RegistrationStatus::UnderReview, $admin);
        $service->transition($reg, RegistrationStatus::WaitingPayment, $admin);

        return $reg->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
