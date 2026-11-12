<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\PaymentRefunds\Pages\ListPaymentRefunds;
use App\Filament\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Filament\Resources\Reconciliation\Pages\ListStatementLines;
use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\Registration;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use App\Services\RefundService;
use App\Services\RegistrationService;
use App\Support\FinanceSettings;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Panel admin Roadmap 5C: menu keuangan berisi data, izin, dan aksi utama lewat Filament (Livewire) → service. */
class FinanceAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
        $this->finance = User::factory()->create(['role' => User::ROLE_FINANCE, 'name' => 'Fina Finance']);
        $member = User::factory()->create(['email' => 'budi@example.com', 'name' => 'Budi Santoso']);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Budi Santoso', 'email' => 'budi@example.com', 'phone' => '1', 'consent' => true])->assertCreated();
        $reg = Registration::query()->firstOrFail();
        app(RegistrationService::class)->transition($reg, RegistrationStatus::WaitingPayment, $this->finance);
        $this->invoice = $reg->invoices()->firstOrFail();
        app(PaymentService::class)->submitProof($this->invoice, $member, UploadedFile::fake()->image('bukti.jpg'), ['amount' => (float) $this->invoice->total, 'transfer_date' => today()->toDateString()]);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->finance);
    }

    public function test_finance_pages_render_with_data_and_permissions(): void
    {
        $tx = PaymentTransaction::query()->firstOrFail();
        $this->get('/admin/invoices')->assertOk()->assertSee($this->invoice->invoice_number)->assertSee('Budi Santoso')->assertSee('Menunggu verifikasi');
        $this->get("/admin/invoices/{$this->invoice->invoice_number}")->assertOk()->assertSee('Ditagihkan ke')->assertSee('Perpanjang jatuh tempo');
        $this->get('/admin/payment-transactions')->assertOk()->assertSee($tx->code)->assertSee('Lihat bukti');
        $this->get('/admin/payment-refunds')->assertOk();
        $this->get('/admin/reconciliation')->assertOk()->assertSee('Menunggu verifikasi')->assertSee('Unggah mutasi rekening');
        $this->get("/admin/invoices/{$this->invoice->invoice_number}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $marketing = User::factory()->create(['role' => User::ROLE_MARKETING]);
        $this->actingAs($marketing);
        foreach (['invoices', 'payment-transactions', 'payment-refunds', 'reconciliation'] as $slug) {
            $this->get("/admin/$slug")->assertForbidden();
        }
        $this->get("/admin/invoices/{$this->invoice->invoice_number}/pdf")->assertForbidden();
    }

    public function test_verify_reject_record_and_settings_actions(): void
    {
        $tx = PaymentTransaction::query()->firstOrFail();
        Livewire::test(ListPaymentTransactions::class)->assertCanSeeTableRecords([$tx])
            ->callTableAction('reject', $tx, ['reason' => 'Bukti buram'])->assertHasNoTableActionErrors();
        $this->assertSame('failed', $tx->refresh()->status);
        $this->assertSame('failed', $this->invoice->refresh()->status);

        // finance mencatat dana masuk langsung dari halaman invoice
        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->invoice_number])
            ->callAction('record', ['amount' => (float) $this->invoice->total, 'method' => 'bank_transfer', 'transfer_date' => today()->toDateString(), 'payer_name' => 'Budi', 'settle' => false, 'note' => 'Mutasi 10/11'])
            ->assertHasNoActionErrors();
        $this->assertSame('paid', $this->invoice->refresh()->status);
        $this->assertSame('confirmed', $this->invoice->registration->status->value);
        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->invoice_number])->assertActionHidden('record')->assertActionHidden('cancel')->assertActionVisible('refund');
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class])
            ->assertOk()->assertSee('Terverifikasi')->assertSee('Ditolak');

        // refund: ajukan oleh finance → penyetuju lain (management) → catat transfer balik
        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->invoice_number])
            ->callAction('refund', ['amount' => 500000, 'reason' => 'Refund sebagian sesuai kebijakan'])->assertHasNoActionErrors();
        $refund = PaymentRefund::query()->firstOrFail();
        Livewire::test(ListPaymentRefunds::class)->assertTableActionHidden('approve', $refund);   // pengaju tidak bisa menyetujui
        $this->actingAs(User::factory()->create(['role' => User::ROLE_MANAGEMENT]));
        Livewire::test(ListPaymentRefunds::class)->callTableAction('approve', $refund, ['note' => 'ok'])->assertHasNoTableActionErrors();
        $this->actingAs($this->finance);
        Livewire::test(ListPaymentRefunds::class)->callTableAction('complete', $refund, ['reference' => 'TRF-9', 'transferred_on' => today()->toDateString()])->assertHasNoTableActionErrors();
        $this->assertSame(500000.0, (float) $this->invoice->refresh()->amount_refunded);

        // pengaturan pajak dari daftar invoice
        Livewire::test(ListInvoices::class)->callAction('settings', ['tax_rate' => 11, 'tax_label' => 'PPN', 'due_days' => 3, 'corporate_due_days' => 14, 'invoice_notes' => null])->assertHasNoActionErrors();
        $this->assertSame(11.0, FinanceSettings::taxRate());
    }

    public function test_reconciliation_match_and_ignore_actions(): void
    {
        $tx = PaymentTransaction::query()->firstOrFail();
        $path = tempnam(sys_get_temp_dir(), 'mts');
        file_put_contents($path, "tanggal,keterangan,kredit\n".today()->format('Y-m-d').",TRANSFER BUDI,123\n".today()->format('Y-m-d').",BUNGA,99\n");
        app(ReconciliationService::class)->import($path, 'm.csv', $this->finance);
        [$line, $bunga] = [BankStatementLine::query()->where('description', 'TRANSFER BUDI')->firstOrFail(), BankStatementLine::query()->where('description', 'BUNGA')->firstOrFail()];

        Livewire::test(ListStatementLines::class)->assertCanSeeTableRecords([$line, $bunga])
            ->callTableAction('match', $line, ['payment_transaction_id' => $tx->id])->assertHasNoTableActionErrors()
            ->callTableAction('ignore', $bunga, ['note' => 'Bunga bank'])->assertHasNoTableActionErrors();
        $this->assertSame($tx->id, $line->refresh()->payment_transaction_id);
        $this->assertSame('manual', $line->match_method);
        $this->assertSame('ignored', $bunga->refresh()->status);
        // verifikasi dari antrean memakai nominal mutasi
        Livewire::test(ListPaymentTransactions::class)->callTableAction('verify', $tx, ['amount_received' => (float) $tx->amount, 'transfer_date' => today()->toDateString(), 'settle' => false])->assertHasNoTableActionErrors();
        $this->assertSame('paid', $tx->refresh()->status);
        $this->assertSame(0.0, app(RefundService::class)->refundable($this->invoice->refresh()) - (float) $this->invoice->amount_paid);
    }
}
