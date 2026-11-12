<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\BankStatementLine;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\PaymentTransaction;
use App\Models\Registration;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\PaymentTransactionRejected;
use App\Notifications\RegistrationStatusChanged;
use App\Services\InvoiceService;
use App\Services\OrganizationService;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use App\Services\RefundService;
use App\Services\RegistrationService;
use App\Services\TrainingRequestService;
use App\Support\FinanceSettings;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Roadmap 5 tahap 5C — Transaksi dengan pembayaran MANUAL: invoice lengkap (item, diskon, pajak, jatuh tempo),
 * payment_transactions, idempotensi verifikasi, kurang/lebih bayar, kedaluwarsa, refund, rekonsiliasi mutasi, dan
 * keuangan di portal perusahaan.
 */
class PaymentTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
        $this->admin = User::factory()->create(['role' => User::ROLE_FINANCE, 'name' => 'Fina Finance']);
        $this->member = User::factory()->create(['email' => 'peserta@example.com', 'name' => 'Peserta Satu']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Pendaftaran individu milik $this->member sampai "menunggu pembayaran". */
    private function waitingRegistration(): Registration
    {
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/registrations', [
            'schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Peserta Satu', 'email' => 'peserta@example.com', 'phone' => '0812', 'consent' => true,
        ])->assertCreated();
        $this->app['auth']->forgetGuards();
        $reg = Registration::query()->latest('id')->firstOrFail();
        app(RegistrationService::class)->transition($reg, RegistrationStatus::WaitingPayment, $this->admin);

        return $reg->refresh();
    }

    private function upload(Registration $reg, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->member, 'sanctum')->post("/api/v1/me/registrations/{$reg->registration_number}/payment-proof",
            ['proof' => UploadedFile::fake()->image('bukti.jpg')] + $extra, ['Accept' => 'application/json']);
    }

    public function test_registration_invoice_has_items_discount_and_configurable_tax(): void
    {
        $reg = $this->waitingRegistration();
        $inv = $reg->invoices()->firstOrFail();
        $price = (float) $reg->price_quoted;
        $this->assertSame('pending', $inv->status);
        $this->assertCount(1, $inv->items);
        $this->assertSame($price, (float) $inv->subtotal);
        $this->assertSame(0.0, (float) $inv->tax_amount);
        $this->assertSame($price, (float) $inv->total);
        $this->assertSame('2026-11-13', $inv->due_date->toDateString());
        $this->assertSame('Peserta Satu', $inv->bill_to_name);

        // tarif pajak 0% → baris pajak disembunyikan
        $this->actingAs($this->member, 'sanctum')->getJson("/api/v1/me/invoices/{$inv->invoice_number}")->assertOk()
            ->assertJsonPath('data.showTax', false)->assertJsonPath('data.payable', true)->assertJsonPath('data.items.0.quantity', 1);

        // tarif pajak diatur → invoice BARU memakai pajak; diskon dikurangkan sebelum pajak
        FinanceSettings::save(['tax_rate' => 11, 'tax_label' => 'PPN', 'due_days' => 5, 'corporate_due_days' => 14]);
        $inv2 = app(InvoiceService::class)->fill(Invoice::query()->create(['invoice_number' => Invoice::nextNumber(), 'registration_id' => $reg->id, 'bill_to_name' => 'X', 'status' => 'cancelled']),
            [['description' => 'A', 'quantity' => 2, 'unit_price' => 1000000]], 200000, 'Promo');
        $this->assertSame(2000000.0, (float) $inv2->subtotal);
        $this->assertSame(198000.0, (float) $inv2->tax_amount);
        $this->assertSame(1998000.0, (float) $inv2->total);
        $this->assertSame('PPN', $inv2->tax_label);
        $this->assertSame((float) $price, (float) $inv->refresh()->total, 'invoice lama tidak berubah');

        // pembuatan invoice idempoten: transisi ulang ke menunggu pembayaran tidak membuat invoice kedua
        app(InvoiceService::class)->forRegistration($reg, $this->admin);
        $this->assertSame(1, $reg->invoices()->where('status', '!=', 'cancelled')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'invoice.created')->exists());
    }

    public function test_proof_creates_pending_transaction_and_double_verification_has_no_side_effects(): void
    {
        $account = PaymentAccount::query()->create(['type' => 'virtual_account', 'bank_name' => 'BCA', 'account_number' => '8888123', 'account_name' => 'PT PPTI', 'is_active' => true, 'sort' => 1]);
        $reg = $this->waitingRegistration();
        $inv = $reg->invoices()->firstOrFail();

        $this->upload($reg, ['payment_account_id' => $account->id, 'transfer_date' => '2026-11-10', 'note' => 'via m-banking'])
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.awaitingVerification', true)
            ->assertJsonPath('data.transactions.0.destination', 'BCA (Virtual Account) 8888123 a.n. PT PPTI');
        $tx = PaymentTransaction::query()->firstOrFail();
        $this->assertSame('pending', $tx->status);
        $this->assertSame((float) $inv->total, (float) $tx->amount);
        $this->assertSame('virtual_account', $tx->method);
        $this->assertSame('payment_verification', $reg->refresh()->status->value);
        // klaim pembayar TIDAK mengubah status invoice
        $this->assertSame('pending', $inv->refresh()->status);

        $svc = app(PaymentService::class);
        $svc->verify($tx, $this->admin);
        $this->assertSame('paid', $inv->refresh()->status);
        $this->assertSame((float) $inv->total, (float) $inv->amount_paid);
        $this->assertSame('confirmed', $reg->refresh()->status->value);
        $this->assertSame(1, Enrollment::query()->where('registration_id', $reg->id)->count());
        $sent = Notification::sent($reg, RegistrationStatusChanged::class)->count();
        $audits = AuditLog::query()->count();

        // verifikasi ganda (klik dua kali / dua finance bersamaan) → tidak ada enrollment, notifikasi, atau audit baru
        $svc->verify($tx->refresh(), $this->admin);
        $svc->verify($tx->refresh(), User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
        $this->assertSame(1, Enrollment::query()->where('registration_id', $reg->id)->count());
        $this->assertSame($sent, Notification::sent($reg, RegistrationStatusChanged::class)->count());
        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertSame((float) $inv->total, (float) $inv->refresh()->amount_paid);

        // invoice lunas tidak menerima bukti baru (API & service)
        $this->actingAs($this->member, 'sanctum')->post("/api/v1/me/invoices/{$inv->invoice_number}/proof", ['proof' => UploadedFile::fake()->image('b2.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.invoice.0', 'Invoice ini sudah lunas — tidak menerima bukti pembayaran baru.');
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.verified')->count());
    }

    public function test_rejection_marks_failed_and_payer_can_submit_again(): void
    {
        $reg = $this->waitingRegistration();
        $this->upload($reg)->assertOk();
        $tx = PaymentTransaction::query()->firstOrFail();
        app(PaymentService::class)->reject($tx, $this->admin, 'Dana belum masuk ke rekening');

        $inv = $reg->invoices()->firstOrFail();
        $this->assertSame('failed', $tx->refresh()->status);
        $this->assertSame('failed', $inv->status);
        $this->assertSame('waiting_payment', $reg->refresh()->status->value);
        $this->assertStringContainsString('Dana belum masuk', $reg->status_note);
        // email status pendaftaran sudah memuat alasan → tidak ada email penolakan kedua
        Notification::assertNotSentTo($this->member, PaymentTransactionRejected::class);

        $this->upload($reg)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.statusLabel', 'Menunggu verifikasi')
            ->assertJsonPath('data.transactions.1.reviewNote', 'Dana belum masuk ke rekening');
        $this->assertSame(2, PaymentTransaction::query()->count());

        // bukti pengganti sebelum diverifikasi → bukti lama dibatalkan, hanya satu yang menunggu
        $this->upload($reg)->assertOk();
        $this->assertSame(1, PaymentTransaction::query()->where('status', 'pending')->count());
        $this->assertSame(1, PaymentTransaction::query()->where('status', 'cancelled')->count());
    }

    public function test_underpayment_stays_open_unless_settled_and_overpayment_is_recorded(): void
    {
        $reg = $this->waitingRegistration();
        $inv = $reg->invoices()->firstOrFail();
        $total = (float) $inv->total;
        $svc = app(PaymentService::class);

        $this->upload($reg, ['amount' => $total - 1000000])->assertOk();
        $svc->verify(PaymentTransaction::query()->firstOrFail(), $this->admin, $total - 1000000);
        $inv->refresh();
        $this->assertSame('pending', $inv->status, 'kurang bayar → invoice tetap terbuka');
        $this->assertSame(1000000.0, $inv->outstanding());
        $this->assertSame(-1000000.0, (float) PaymentTransaction::query()->first()->difference);
        $this->assertSame('waiting_payment', $reg->refresh()->status->value);
        $this->assertSame(0, Enrollment::query()->count());

        // pelunasan sisa (lebih 5.000) → lunas, lebih bayar dicatat
        $this->upload($reg, ['amount' => 1005000])->assertOk()->assertJsonPath('data.outstanding', 1000000);
        $svc->verify(PaymentTransaction::query()->where('status', 'pending')->firstOrFail(), $this->admin, 1005000);
        $inv->refresh();
        $this->assertSame('paid', $inv->status);
        $this->assertSame($total + 5000, (float) $inv->amount_paid);
        $this->assertStringContainsString('Lebih bayar Rp 5.000', $inv->settlement_note);
        $this->assertSame('confirmed', $reg->refresh()->status->value);

        // kurang bayar yang dinyatakan lunas finance (mis. potongan biaya transfer)
        $other = User::factory()->create(['email' => 'dua@example.com']);
        $this->member = $other;
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Dua', 'email' => 'dua@example.com', 'phone' => '1', 'consent' => true])->assertCreated();
        $reg2 = Registration::query()->latest('id')->first();
        app(RegistrationService::class)->transition($reg2, RegistrationStatus::WaitingPayment, $this->admin);
        $this->upload($reg2->refresh())->assertOk();
        $tx = PaymentTransaction::query()->where('status', 'pending')->firstOrFail();
        $svc->verify($tx, $this->admin, (float) $tx->amount - 6500, null, 'Potongan biaya transfer antarbank', true);
        $inv2 = $reg2->invoices()->first();
        $this->assertSame('paid', $inv2->status);
        $this->assertStringContainsString('Kurang bayar Rp 6.500 dinyatakan lunas', $inv2->settlement_note);
        $this->assertSame('confirmed', $reg2->refresh()->status->value);
    }

    public function test_overdue_invoices_expire_and_can_be_extended(): void
    {
        $reg = $this->waitingRegistration();
        $inv = $reg->invoices()->firstOrFail();

        // invoice lain yang punya bukti menunggu verifikasi tidak ikut kedaluwarsa
        $this->member = User::factory()->create(['email' => 'tiga@example.com']);
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Tiga', 'email' => 'tiga@example.com', 'phone' => '1', 'consent' => true])->assertCreated();
        $reg3 = Registration::query()->latest('id')->first();
        app(RegistrationService::class)->transition($reg3, RegistrationStatus::WaitingPayment, $this->admin);
        $this->upload($reg3->refresh())->assertOk();

        Carbon::setTestNow('2026-11-14 01:00:00');
        $this->artisan('invoices:expire')->expectsOutputToContain('Invoice kedaluwarsa: 1')->assertSuccessful();
        $this->assertSame('expired', $inv->refresh()->status);
        $this->assertSame('pending', $reg3->invoices()->first()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'invoice.expired')->where('auditable_id', $inv->id)->exists());

        $this->member = User::query()->where('email', 'peserta@example.com')->first();
        $this->upload($reg)->assertStatus(422)->assertJsonPath('errors.invoice.0', 'Invoice ini sudah lewat jatuh tempo. Hubungi tim VMES untuk memperpanjang tagihan.');

        app(InvoiceService::class)->extend($inv, '2026-11-20', $this->admin);
        $this->assertSame('pending', $inv->refresh()->status);
        $this->upload($reg)->assertOk();
    }

    public function test_refund_needs_reason_independent_approver_and_transfer_reference(): void
    {
        $reg = $this->waitingRegistration();
        $inv = $reg->invoices()->firstOrFail();
        app(PaymentService::class)->recordReceived($inv, $this->admin, ['amount' => (float) $inv->total, 'transfer_date' => '2026-11-10']);
        $this->assertSame('paid', $inv->refresh()->status);
        $refunds = app(RefundService::class);

        try {
            $refunds->request($inv, $this->admin, 1000000, 'batal');
            $this->fail('alasan terlalu pendek harus ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $refund = $refunds->request($inv, $this->admin, 1000000, 'Peserta sakit, refund sebagian sesuai kebijakan');
        try {
            $refunds->request($inv, $this->admin, (float) $inv->total, 'Melebihi dana yang masih bisa direfund');
            $this->fail('nominal melebihi sisa yang bisa direfund');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        // pengaju tidak boleh menyetujui; finance tanpa izin approve juga tidak
        foreach ([$this->admin, User::factory()->create(['role' => User::ROLE_FINANCE])] as $who) {
            try {
                $refunds->approve($refund, $who);
                $this->fail('harus ditolak');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('approver', $e->errors());
            }
        }
        $manager = User::factory()->create(['role' => User::ROLE_MANAGEMENT]);
        try {
            $refunds->complete($refund, $this->admin, 'TRF-BALIK-1', '2026-11-10');
            $this->fail('belum disetujui');
        } catch (ValidationException) {
        }
        $refunds->approve($refund, $manager, 'OK');
        $this->assertSame($manager->id, $refund->refresh()->decided_by);
        try {
            $refunds->complete($refund, $this->admin, ' ', '2026-11-10');
            $this->fail('referensi wajib');
        } catch (ValidationException) {
        }
        $refunds->complete($refund, $this->admin, 'TRF-BALIK-1', '2026-11-10');
        $inv->refresh();
        $this->assertSame('paid', $inv->status, 'refund sebagian → invoice tetap lunas');
        $this->assertSame(1000000.0, (float) $inv->amount_refunded);
        $this->assertSame('confirmed', $reg->refresh()->status->value, 'refund tidak membatalkan pendaftaran otomatis');

        $rest = $refunds->request($inv, $this->admin, $refunds->refundable($inv), 'Pembatalan penuh atas permintaan peserta');
        $refunds->approve($rest, $manager);
        $refunds->complete($rest, $this->admin, 'TRF-BALIK-2', '2026-11-10');
        $this->assertSame('refunded', $inv->refresh()->status);
        $this->assertSame(0.0, $refunds->refundable($inv));
        foreach (['refund.requested', 'refund.approved', 'refund.completed', 'invoice.refunded'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }
        // invoice yang sudah direfund tidak menerima bukti baru
        $this->upload($reg)->assertStatus(422);
    }

    public function test_bank_statement_import_auto_matches_unique_candidates_and_skips_duplicates(): void
    {
        $regA = $this->waitingRegistration();
        $this->upload($regA, ['amount' => 7500000, 'transfer_date' => '2026-11-09'])->assertOk();
        $txA = PaymentTransaction::query()->latest('id')->first();

        $this->member = User::factory()->create(['email' => 'b@example.com']);
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'B', 'email' => 'b@example.com', 'phone' => '1', 'consent' => true])->assertCreated();
        $regB = Registration::query()->latest('id')->first();
        app(RegistrationService::class)->transition($regB, RegistrationStatus::WaitingPayment, $this->admin);
        $this->upload($regB->refresh(), ['amount' => 3000000, 'transfer_date' => '2026-11-01'])->assertOk();
        $txB = PaymentTransaction::query()->latest('id')->first();
        $invB = $regB->invoices()->first();

        $csv = "Laporan Mutasi Rekening BCA\n"
            ."Tanggal;Keterangan;Debit;Kredit;Referensi\n"
            ."10/11/2026;TRSF E-BANKING CR PESERTA SATU;;7.500.000,00;FT001\n"
            ."05/11/2026;SETORAN {$invB->invoice_number};;2.999.000,00;FT002\n"
            ."06/11/2026;BIAYA ADMIN;15.000,00;;\n"
            ."07/11/2026;BUNGA;;1.234,56;\n"
            ."xx/11/2026;TANGGAL RUSAK;;100.000,00;\n";
        $path = tempnam(sys_get_temp_dir(), 'mutasi');
        file_put_contents($path, $csv);
        $svc = app(ReconciliationService::class);
        $result = $svc->import($path, 'mutasi-november.csv', $this->admin);

        $this->assertSame(3, $result['imported']);
        $this->assertSame(2, $result['matched']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame($txA->id, BankStatementLine::query()->where('reference', 'FT001')->value('payment_transaction_id'));
        $this->assertSame($txB->id, BankStatementLine::query()->where('reference', 'FT002')->value('payment_transaction_id'), 'nomor invoice di keterangan');
        $this->assertSame('unmatched', BankStatementLine::query()->where('description', 'BUNGA')->value('status'));

        // unggah ulang berkas yang sama → semua baris dilewati
        $again = $svc->import($path, 'mutasi-november.csv', $this->admin);
        $this->assertSame(0, $again['imported']);
        $this->assertSame(3, $again['skipped']);

        $summary = $svc->summary();
        $this->assertSame(1, $summary['unmatched_lines']);
        $this->assertSame(2, $summary['pending']);
        $this->assertSame(0, $summary['pending_without_statement']);
        $this->assertSame(1500000.0, $svc->parseAmount('Rp 1,500,000.00'));
        $this->assertSame(1500000.0, $svc->parseAmount('1.500.000'));
        $this->assertTrue(AuditLog::query()->where('action', 'reconciliation.line_matched')->exists());
    }

    public function test_training_request_invoice_and_corporate_finance_portal_scope(): void
    {
        $orgs = app(OrganizationService::class);
        $abc = $orgs->create(['legal_name' => 'PT ABC Manufaktur', 'tax_id' => '01.234.567.8-901.000', 'invoice_email' => 'ap@abc.co.id', 'invoice_address' => 'Jl. Industri 1, Batam']);
        $other = $orgs->create(['legal_name' => 'PT Lain Sejahtera']);
        $hr = User::factory()->create(['email' => 'hr@abc.co.id']);
        $fin = User::factory()->create(['email' => 'finance@abc.co.id', 'name' => 'Fani AP']);
        $outsider = User::factory()->create(['email' => 'fin@lain.co.id']);
        $orgs->addCorporateUser($abc, $hr, 'hr_admin');
        $orgs->addCorporateUser($abc, $fin, 'finance');
        $orgs->addCorporateUser($other, $outsider, 'finance');
        $schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->firstOrFail();

        $code = $this->actingAs($hr, 'sanctum')->postJson("/api/v1/corporate/organizations/{$abc->code}/requests", [
            'package' => $schedule->package->slug, 'seats' => 4, 'delivery' => 'onsite', 'target_date' => '2026-12-01', 'submit' => true,
        ])->assertCreated()->json('data.code');
        $r = TrainingRequest::query()->where('code', $code)->firstOrFail();
        app(TrainingRequestService::class)->quote($r, $this->admin, 7000000, 1000000, '2026-11-30');
        $this->actingAs($hr, 'sanctum')->postJson("/api/v1/corporate/organizations/{$abc->code}/requests/{$code}/accept-quote")->assertOk();

        // persetujuan → invoice dari penawaran (4 × 7 jt − 1 jt), ditagihkan ke data invoice organisasi
        $inv = Invoice::query()->where('training_request_id', $r->id)->firstOrFail();
        $this->assertSame('approved', $r->refresh()->status);
        $this->assertSame($abc->id, $inv->organization_id);
        $this->assertSame(28000000.0, (float) $inv->subtotal);
        $this->assertSame(27000000.0, (float) $inv->total);
        $this->assertSame('ap@abc.co.id', $inv->bill_to_email);
        $this->assertSame('01.234.567.8-901.000', $inv->bill_to_tax_id);
        $this->assertSame('2026-11-24', $inv->due_date->toDateString());

        $base = "/api/v1/corporate/organizations/{$abc->code}/invoices";
        $this->app['auth']->forgetGuards();
        $this->actingAs($hr, 'sanctum')->getJson($base)->assertForbidden();   // HR tanpa view_finance
        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/corporate/organizations/{$other->code}/invoices/{$inv->invoice_number}")->assertNotFound();   // IDOR antar perusahaan
        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider, 'sanctum')->getJson($base)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($fin, 'sanctum')->getJson($base)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $inv->invoice_number)->assertJsonPath('summary.outstanding', 27000000);
        // invoice perusahaan tidak bisa dibuka lewat portal member
        $this->actingAs($fin, 'sanctum')->getJson("/api/v1/me/invoices/{$inv->invoice_number}")->assertNotFound();
        $this->actingAs($fin, 'sanctum')->get("$base/{$inv->invoice_number}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($hr, 'sanctum')->getJson("/api/v1/corporate/organizations/{$abc->code}/requests/{$code}")->assertOk()
            ->assertJsonPath('data.invoice.number', $inv->invoice_number)->assertJsonPath('data.invoice.canOpen', false);

        // finance perusahaan unggah bukti → menunggu verifikasi VMES; status permintaan belum berubah
        $this->actingAs($fin, 'sanctum')->post("$base/{$inv->invoice_number}/proof", ['proof' => UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'), 'amount' => 27000000, 'transfer_date' => '2026-11-10'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.awaitingVerification', true);
        $tx = PaymentTransaction::query()->firstOrFail();
        $this->assertSame('corporate', $tx->source);
        $this->assertSame('approved', $r->refresh()->status);

        app(PaymentService::class)->verify($tx, $this->admin);
        $this->assertSame('paid', $inv->refresh()->status);
        $this->assertSame('paid', $r->refresh()->status);
        $this->assertStringContainsString($tx->code, $r->payment_reference);

        // penolakan bukti pada invoice perusahaan → email ke pengunggah
        $r2code = $this->actingAs($hr, 'sanctum')->postJson("/api/v1/corporate/organizations/{$abc->code}/requests", [
            'package' => $schedule->package->slug, 'seats' => 1, 'delivery' => 'onsite', 'submit' => true,
        ])->json('data.code');
        $r2 = TrainingRequest::query()->where('code', $r2code)->firstOrFail();
        app(TrainingRequestService::class)->quote($r2, $this->admin, 5000000);
        $this->actingAs($hr, 'sanctum')->postJson("/api/v1/corporate/organizations/{$abc->code}/requests/{$r2code}/accept-quote")->assertOk();
        $inv2 = $r2->invoices()->first();
        $this->actingAs($fin, 'sanctum')->post("$base/{$inv2->invoice_number}/proof", ['proof' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertOk();
        app(PaymentService::class)->reject(PaymentTransaction::query()->where('status', 'pending')->firstOrFail(), $this->admin, 'Nominal tidak terbaca');
        Notification::assertSentTo($fin, PaymentTransactionRejected::class);
        // permintaan dibatalkan → invoice terbuka tanpa pembayaran ikut dibatalkan
        app(TrainingRequestService::class)->cancel($r2->refresh(), $this->admin, 'Anggaran ditunda');
        $this->assertSame('cancelled', $inv2->refresh()->status);
    }

    public function test_legacy_payments_are_migrated_to_invoices_and_transactions(): void
    {
        $reg = $this->waitingRegistration();
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful(); // 5D engine notifikasi + 5C invoice
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('payments'));
        DB::table('payments')->delete();
        $row = fn (string $no, string $status, array $extra = []) => DB::table('payments')->insert($extra + [
            'registration_id' => $reg->id, 'invoice_number' => $no, 'amount' => 2500000, 'installment_no' => 1, 'installment_total' => 1, 'due_date' => '2026-11-20',
            'method' => 'bank_transfer', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row('INV-202611-0101', 'unpaid');
        $row('INV-202611-0102', 'submitted', ['proof_path' => 'payments/a.jpg', 'proof_uploaded_at' => now()]);
        $row('INV-202611-0103', 'verified', ['proof_path' => 'payments/b.jpg', 'proof_uploaded_at' => now(), 'verified_by' => $this->admin->id, 'verified_at' => now()]);
        $row('INV-202611-0104', 'rejected', ['proof_path' => 'payments/c.jpg', 'proof_uploaded_at' => now(), 'note' => 'Buram', 'verified_at' => now()]);
        $row('INV-202611-0105', 'refunded', ['proof_path' => 'payments/d.jpg', 'proof_uploaded_at' => now(), 'verified_at' => now()]);

        $this->artisan('migrate')->assertSuccessful();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('payments'));
        $status = fn (string $no) => Invoice::query()->where('invoice_number', $no)->first();
        $this->assertSame('pending', $status('INV-202611-0101')->status);
        $this->assertSame(0, $status('INV-202611-0101')->transactions()->count());
        $this->assertSame('pending', $status('INV-202611-0102')->transactions()->first()->status);
        $this->assertSame('paid', $status('INV-202611-0103')->status);
        $this->assertSame(2500000.0, (float) $status('INV-202611-0103')->amount_paid);
        $this->assertSame($this->admin->id, $status('INV-202611-0103')->transactions()->first()->verified_by);
        $this->assertSame('failed', $status('INV-202611-0104')->status);
        $this->assertSame('Buram', $status('INV-202611-0104')->transactions()->first()->review_note);
        $this->assertSame('refunded', $status('INV-202611-0105')->status);
        $this->assertSame('completed', $status('INV-202611-0105')->refunds()->first()->status);
        $this->assertSame(1, $status('INV-202611-0101')->items()->count());
        $this->assertNull($status('INV-202611-0101')->organization_id);
    }
}
