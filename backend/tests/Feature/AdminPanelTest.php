<?php

namespace Tests\Feature;

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
                'TMS: Pendaftaran &amp; Keuangan', 'Pendaftaran', 'Pembayaran',
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
        foreach (['registrations', 'payments', 'audit-logs', 'trainers', 'waitlists', 'attendances', 'promo-codes', 'laporan', 'certificates', 'certificates/create', 'certificate-templates', 'certificate-templates/create', 'dashboard-eksekutif', 'api-clients', 'api-clients/create'] as $slug) {
            $this->actingAs($admin)->get("/admin/$slug")->assertOk();
        }
    }
}
