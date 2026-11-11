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
        $this->get('/admin/login')->assertOk();
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
        foreach (['registrations', 'payments', 'audit-logs', 'trainers', 'waitlists', 'attendances', 'promo-codes', 'laporan', 'certificates', 'certificates/create', 'certificate-templates', 'certificate-templates/create'] as $slug) {
            $this->actingAs($admin)->get("/admin/$slug")->assertOk();
        }
    }
}
