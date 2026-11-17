<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
