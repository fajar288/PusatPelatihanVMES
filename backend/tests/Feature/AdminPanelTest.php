<?php

namespace Tests\Feature;

use App\Filament\Resources\Registrations\Pages\EditRegistration;
use App\Models\Registration;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('images/logo-admin.png', false)
            ->assertSee('images/logo-admin-dark.png', false)
            ->assertSee('images/favicon-admin.png', false);
    }

    public function test_dashboard_shows_logo_and_grouped_navigation(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('Selamat datang, '.$admin->name)
            ->assertSee('images/logo-admin.png', false)
            // Grup tampil sesuai urutan enum NavigationGroup, menu landing page & TMS di grupnya masing-masing.
            ->assertSeeInOrder([
                'Laporan &amp; Analitik', 'Dashboard Eksekutif',
                'Landing Page (Website)', 'Banner', 'Berita &amp; artikel', 'Pengaturan situs',
                'TMS: Program &amp; Kelas', 'Kategori training', 'Jadwal &amp; kelas',
                'TMS: Pendaftaran &amp; Keuangan', 'Pendaftaran', 'Invoice', 'Verifikasi pembayaran', 'Rekonsiliasi', 'Refund',
                'TMS: Pelaksanaan &amp; Penilaian', 'Kehadiran', 'Penilaian kompetensi',
                'LMS (E-learning)', 'Sertifikat &amp; Alumni', 'Notifikasi', 'Sistem &amp; Integrasi', 'Audit log',
            ], false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_student_cannot_access_panel(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_can_open_dashboard_and_resources(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($admin)->get('/admin')->assertOk();
        foreach (['posts', 'pages', 'partners', 'testimonials', 'galleries', 'banners', 'menus', 'site-settings', 'training-categories', 'training-packages', 'training-schedules'] as $slug) {
            $this->actingAs($admin)->get("/admin/$slug")->assertOk();
            $this->actingAs($admin)->get("/admin/$slug/create")->assertOk();
        }
        foreach (['registrations', 'invoices', 'payment-transactions', 'payment-refunds', 'reconciliation', 'audit-logs', 'trainers', 'waitlists', 'attendances', 'promo-codes', 'laporan', 'certificates', 'certificates/create', 'certificate-templates', 'certificate-templates/create', 'dashboard-eksekutif', 'api-clients', 'api-clients/create'] as $slug) {
            $this->actingAs($admin)->get("/admin/$slug")->assertOk();
        }
    }

    public function test_laporan_preview_table_scrolls_inside_its_card(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $package = TrainingPackage::query()->create(['slug' => 'uji-lebar', 'title' => 'Uji Lebar', 'status' => 'published']);
        $schedule = TrainingSchedule::query()->create(['package_id' => $package->id, 'code' => 'UJI-LBR', 'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'status' => 'open', 'quota' => 20]);
        Registration::query()->create([
            'registration_number' => 'REG-TEST-LBR', 'schedule_id' => $schedule->id, 'package_id' => $package->id, 'type' => 'individual',
            'status' => 'pending', 'name' => 'Peserta Lebar', 'email' => 'lebar@example.com', 'phone' => '0812', 'participants_count' => 1, 'consent' => true, 'total' => 1000000,
        ]);

        $this->actingAs($admin)->get('/admin/laporan')->assertOk()
            // Kolom grid dibatasi agar wadah tabel memotong dan menampilkan scrollbar, bukan ikut melebar.
            ->assertSee('.ppti-lp { display: grid; grid-template-columns: minmax(0, 1fr);', false)
            ->assertSee('class="ppti-lp-tablewrap" tabindex="0" role="region" aria-label="Pratinjau Pendaftaran, geser ke samping untuk kolom lainnya"', false)
            ->assertSee('REG-TEST-LBR');
    }

    public function test_registration_documents_open_through_signed_private_urls(): void
    {
        // disk lokal sungguhan (bukan Storage::fake) agar URL sementara & rute penyajian berkas yang asli ikut diuji
        $root = storage_path('framework/testing/disks/local-signed');
        config(['filesystems.disks.local.root' => $root, 'filesystems.disks.local.serve' => true]);
        Storage::forgetDisk('local');
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($root));
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $package = TrainingPackage::query()->create(['slug' => 'uji-dok', 'title' => 'Uji Dokumen', 'status' => 'published']);
        $schedule = TrainingSchedule::query()->create(['package_id' => $package->id, 'code' => 'UJI-DOK', 'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'status' => 'open', 'quota' => 20]);
        $path = 'registrations/REG-TEST-DOK/abc123.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 uji');
        $reg = Registration::query()->create([
            'registration_number' => 'REG-TEST-DOK', 'schedule_id' => $schedule->id, 'package_id' => $package->id, 'type' => 'individual',
            'status' => 'pending', 'name' => 'Peserta Dok', 'email' => 'dok@example.com', 'phone' => '0812', 'participants_count' => 1, 'consent' => true,
            'documents' => [$path], 'document_names' => [$path => 'KTP Peserta.pdf'],
        ]);

        $this->actingAs($admin)->get("/admin/registrations/{$reg->registration_number}/edit")->assertOk()
            ->assertSee('Dokumen pendukung')
            ->assertSee('KTP Peserta.pdf');

        $component = Livewire::actingAs($admin)->test(EditRegistration::class, ['record' => $reg->registration_number]);
        $files = $component->instance()->form->getComponent('documents')->getUploadedFiles();
        $url = collect($files)->first()['url'];
        $this->assertStringContainsString('signature=', $url, 'berkas privat harus dibuka lewat URL bertanda tangan');
        $this->get($url)->assertOk();
        // tanpa tanda tangan berkas tidak bisa diambil
        $this->get('/storage/'.$path)->assertForbidden();
    }
}
