<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\Registration;
use App\Models\User;
use App\Models\Waitlist;
use App\Services\MemberService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Hak subjek data (ekspor) dan aturan retensi yang bisa dikonfigurasi (mati bawaan). */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Storage::fake(Registration::DOCUMENT_DISK);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function register(array $over = [], ?User $as = null): Registration
    {
        $req = $as ? $this->actingAs($as, 'sanctum') : $this;
        $req->post('/api/v1/registrations', array_merge([
            'schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Rizky', 'email' => 'rizky@example.com', 'phone' => '0812', 'consent' => true,
        ], $over), ['Accept' => 'application/json'])->assertCreated();
        $this->app['auth']->forgetGuards();

        return Registration::query()->latest('id')->firstOrFail();
    }

    public function test_member_downloads_own_data_only(): void
    {
        $user = User::factory()->create(['email' => 'rizky@example.com', 'name' => 'Rizky']);
        app(MemberService::class)->createForUser($user, ['phone' => '0812', 'company' => 'PT A']);
        $this->register(['documents' => [UploadedFile::fake()->create('ktp.pdf', 10, 'application/pdf')]], $user);
        $this->register(['email' => 'orang-lain@example.com', 'name' => 'Orang Lain']);

        $res = $this->actingAs($user, 'sanctum')->get('/api/v1/me/data-export')->assertOk();
        $this->assertStringContainsString('attachment; filename="data-pribadi-MBR-', $res->headers->get('Content-Disposition'));
        $data = json_decode($res->getContent(), true);
        $this->assertSame('rizky@example.com', $data['account']['email']);
        $this->assertSame('PT A', $data['member']['company']);
        $this->assertCount(1, $data['registrations']);
        $this->assertSame(['ktp.pdf'], $data['registrations'][0]['documents']);
        $this->assertStringNotContainsString('Orang Lain', $res->getContent());
        $this->assertStringNotContainsString('registrations/', $res->getContent(), 'path penyimpanan tidak ikut diekspor');
        $this->assertSame(1, AuditLog::query()->where('action', 'privacy.data_exported')->count());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me/data-export')->assertUnauthorized();
    }

    public function test_retention_rules_are_off_by_default(): void
    {
        $reg = $this->register(['documents' => [UploadedFile::fake()->create('ktp.pdf', 10, 'application/pdf')]]);
        $reg->forceFill(['status' => RegistrationStatus::Cancelled])->save();
        Carbon::setTestNow('2028-01-01 00:00:00');

        $this->artisan('privacy:prune')->expectsOutputToContain('mati (atur di .env)')->assertSuccessful();
        $this->assertSame('Rizky', $reg->refresh()->name);
        $this->assertCount(1, $reg->documentList());
    }

    public function test_enabled_rules_prune_documents_anonymize_closed_registrations_and_old_waitlists(): void
    {
        config(['privacy.registration_documents_days' => 90, 'privacy.closed_registration_days' => 365, 'privacy.waitlist_days' => 30]);

        $confirmed = $this->register(['email' => 'a@example.com', 'documents' => [UploadedFile::fake()->create('surat.pdf', 10, 'application/pdf')]]);
        $confirmed->forceFill(['status' => RegistrationStatus::Confirmed])->save();
        $cancelled = $this->register(['email' => 'b@example.com', 'name' => 'Budi Batal', 'notes' => 'catatan pribadi', 'documents' => [UploadedFile::fake()->create('ktp.pdf', 10, 'application/pdf')]]);
        $cancelled->forceFill(['status' => RegistrationStatus::Cancelled])->save();
        $pending = $this->register(['email' => 'c@example.com', 'documents' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]]);
        $schedule = $confirmed->schedule;
        Waitlist::query()->create(['schedule_id' => $schedule->id, 'name' => 'Tunggu', 'email' => 't@example.com', 'participants_count' => 1, 'status' => 'waiting']);
        $docPath = $confirmed->documentList()[0]['path'];

        // belum lewat batas: tidak ada yang diproses
        Carbon::setTestNow('2026-12-01 00:00:00');
        $this->artisan('privacy:prune', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('privacy:prune')->assertSuccessful();
        $this->assertCount(1, $confirmed->refresh()->documentList());

        // 100 hari kemudian: dokumen pendaftaran selesai dihapus, yang masih berjalan tidak
        Carbon::setTestNow('2027-02-20 00:00:00');
        $this->artisan('privacy:prune')->assertSuccessful();
        $this->assertSame([], $confirmed->refresh()->documentList());
        Storage::disk(Registration::DOCUMENT_DISK)->assertMissing($docPath);
        $this->assertCount(1, $pending->refresh()->documentList());
        $this->assertSame(0, Waitlist::query()->count(), 'jadwal CCNA-2611 sudah selesai > 30 hari');

        // > 1 tahun: pendaftaran batal tanpa invoice dianonimkan, nomor & status tetap
        Carbon::setTestNow('2028-01-10 00:00:00');
        $this->artisan('privacy:prune')->assertSuccessful();
        $cancelled->refresh();
        $this->assertSame('Data dihapus', $cancelled->name);
        $this->assertNull($cancelled->notes);
        $this->assertStringEndsWith('@invalid.local', $cancelled->email);
        $this->assertSame(RegistrationStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->registration_number);
        $this->assertSame('Rizky', $confirmed->refresh()->name, 'pendaftaran terkonfirmasi tidak dianonimkan');
        $this->assertDatabaseHas('audit_logs', ['action' => 'privacy.registration_anonymized', 'auditable_id' => $cancelled->id]);
    }
}
