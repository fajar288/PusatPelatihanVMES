<?php

namespace Tests\Feature;

use App\Filament\Resources\Alumni\AlumniResource;
use App\Filament\Resources\Alumni\Pages\ListAlumni;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\MemberService;
use App\Services\UserAccountService;
use Database\Seeders\RolesSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Menu admin Pengguna, Peserta, Alumni: akses per peran, pengaman akun, akun nonaktif, audit, isi daftar alumni. */
class AdminUsersMembersTest extends TestCase
{
    use RefreshDatabase;

    private UserAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->accounts = app(UserAccountService::class);
    }

    private function user(string $role, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => $role] + $attrs);
        $u->roles()->sync([Role::query()->where('slug', $role)->value('id') => ['created_at' => now()]]);

        return $u->refresh();
    }

    private function member(string $name, array $attrs = []): Member
    {
        $u = User::factory()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'role' => User::ROLE_STUDENT]);
        $m = app(MemberService::class)->createForUser($u);
        if ($attrs) {
            $m->update($attrs);
        }

        return $m->refresh()->load('user');
    }

    private function enroll(Member $m, TrainingSchedule $s, string $status): Enrollment
    {
        return Enrollment::query()->create([
            'schedule_id' => $s->id, 'member_id' => $m->id, 'participant_name' => $m->user->name, 'participant_email' => $m->user->email,
            'status' => $status, 'enrolled_at' => $s->start_date, 'completed_at' => $status === 'completed' ? $s->end_date : null,
        ]);
    }

    private function schedule(string $code, TrainingPackage $p, string $start, string $end): TrainingSchedule
    {
        return TrainingSchedule::query()->create(['package_id' => $p->id, 'code' => $code, 'start_date' => $start, 'end_date' => $end, 'status' => 'completed', 'quota' => 20]);
    }

    /** Guard auth menyimpan user antar-request dalam satu tes: lupakan dulu sebelum berganti akun. */
    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    // ---------------------------------------------------------------- akses per peran

    public function test_access_per_role(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $administrator = $this->user(User::ROLE_ADMINISTRATOR);
        $marketing = $this->user(User::ROLE_MARKETING);
        $finance = $this->user(User::ROLE_FINANCE);
        $m = $this->member('Peserta Akses');

        foreach ([$super, $administrator] as $u) {
            $this->as($u)->get('/admin/pengguna')->assertOk();
            $this->as($u)->get('/admin/peserta')->assertOk();
            $this->as($u)->get('/admin/peserta/'.$m->member_number)->assertOk()->assertSee($m->member_number);
            $this->as($u)->get('/admin/peserta/'.$m->member_number.'/edit')->assertOk();
            $this->as($u)->get('/admin/alumni')->assertOk();
            $this->as($u)->get('/admin/export/members.csv')->assertOk();
            $this->as($u)->get('/admin/export/alumni.xlsx')->assertOk();
        }
        // Peran admin lain tanpa users.manage / members.view tidak melihat menu maupun data pribadi peserta.
        foreach ([$marketing, $finance] as $u) {
            $this->as($u)->get('/admin/pengguna')->assertForbidden();
            $this->as($u)->get('/admin/peserta')->assertForbidden();
            $this->as($u)->get('/admin/peserta/'.$m->member_number)->assertForbidden();
            $this->as($u)->get('/admin/alumni')->assertForbidden();
            $this->as($u)->get('/admin/export/members.csv')->assertForbidden();
            $this->as($u)->get('/admin/export/alumni.csv')->assertForbidden();
        }
        // Menu ikut canViewAny (navigasi panel di-cache per proses, jadi dicek langsung).
        $this->as($marketing);
        $this->assertFalse(UserResource::canViewAny() || MemberResource::canViewAny() || AlumniResource::canViewAny());
        $this->as($administrator);
        $this->assertTrue(UserResource::canViewAny() && MemberResource::canViewAny() && AlumniResource::canViewAny());
        $this->get('/admin')->assertOk()->assertSee('/admin/pengguna', false)->assertSee('/admin/peserta', false)->assertSee('/admin/alumni', false);
        $this->assertTrue(Permission::query()->where('slug', 'members.view')->whereHas('roles', fn ($q) => $q->where('slug', 'administrator'))->exists());
    }

    public function test_user_table_lists_accounts_without_password_and_filters_by_role_and_status(): void
    {
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $trainer = $this->user(User::ROLE_TRAINER, ['name' => 'Trainer Satu']);
        $off = $this->user(User::ROLE_STUDENT, ['name' => 'Akun Nonaktif', 'deactivated_at' => now()]);
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$admin, $trainer, $off])
            ->filterTable('role', User::ROLE_TRAINER)->assertCanSeeTableRecords([$trainer])->assertCanNotSeeTableRecords([$off, $admin])
            ->resetTableFilters()
            ->filterTable('status', 'inactive')->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$trainer])
            ->resetTableFilters()
            ->searchTable('Trainer Satu')->assertCanSeeTableRecords([$trainer])->assertCanNotSeeTableRecords([$off]);

        $this->get('/admin/pengguna')->assertOk()->assertDontSee($trainer->password, false);
    }

    // ---------------------------------------------------------------- pengaman

    public function test_cannot_demote_or_deactivate_self(): void
    {
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $this->user(User::ROLE_ADMINISTRATOR);

        $this->assertThrowsDomain('akun sendiri', fn () => $this->accounts->update($admin, $admin, $admin->name, User::ROLE_MARKETING));
        $this->assertThrowsDomain('akun sendiri', fn () => $this->accounts->deactivate($admin, $admin));
        // Nama sendiri tetap boleh diubah.
        $this->accounts->update($admin, $admin, 'Nama Baru', User::ROLE_ADMINISTRATOR);
        $this->assertSame('Nama Baru', $admin->refresh()->name);
        $this->assertSame([User::ROLE_ADMINISTRATOR], $admin->roleSlugs());
        $this->assertTrue($admin->isActive());
    }

    public function test_only_super_admin_touches_super_admin_accounts_or_role(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $staff = $this->user(User::ROLE_MARKETING);

        $this->assertThrowsDomain('super admin', fn () => $this->accounts->deactivate($admin, $super));
        $this->assertThrowsDomain('super admin', fn () => $this->accounts->update($admin, $super, $super->name, User::ROLE_MARKETING));
        $this->assertThrowsDomain('super admin', fn () => $this->accounts->update($admin, $staff, $staff->name, User::ROLE_SUPER_ADMIN));
        $this->assertTrue($super->refresh()->isSuperAdmin());
        $this->assertFalse($staff->refresh()->isSuperAdmin());

        // Super admin boleh menaikkan peran.
        $this->accounts->update($super, $staff, $staff->name, User::ROLE_SUPER_ADMIN);
        $this->assertTrue($staff->refresh()->isSuperAdmin());
    }

    public function test_last_admin_cannot_be_demoted_or_deactivated(): void
    {
        $administrator = $this->user(User::ROLE_ADMINISTRATOR);
        // Peran lain yang diberi users.manage (mis. lewat RolesSeeder) tetap tidak boleh menghapus pengelola akun terakhir.
        $manager = $this->user(User::ROLE_MANAGEMENT);
        Permission::query()->where('slug', 'users.manage')->first()->roles()->attach(Role::query()->where('slug', 'management')->value('id'));

        $this->assertThrowsDomain('terakhir', fn () => $this->accounts->deactivate($manager, $administrator));
        $this->assertThrowsDomain('terakhir', fn () => $this->accounts->update($manager, $administrator, $administrator->name, User::ROLE_MARKETING));
        $this->assertTrue($administrator->refresh()->isActive());
        $this->assertTrue($administrator->hasRole(User::ROLE_ADMINISTRATOR));

        // Dengan admin kedua, admin pertama boleh diturunkan.
        $this->user(User::ROLE_ADMINISTRATOR);
        $this->accounts->update($manager, $administrator, $administrator->name, User::ROLE_MARKETING);
        $this->assertSame([User::ROLE_MARKETING], $administrator->refresh()->roleSlugs());

        // Super admin aktif terakhir: tidak bisa dinonaktifkan (mis. oleh super admin yang aksesnya sendiri sudah dicabut).
        $lastSuper = $this->user(User::ROLE_SUPER_ADMIN);
        $formerSuper = $this->user(User::ROLE_SUPER_ADMIN, ['deactivated_at' => now()]);
        $this->assertThrowsDomain('super admin aktif terakhir', fn () => $this->accounts->deactivate($formerSuper, $lastSuper));
        $this->assertThrowsDomain('super admin aktif terakhir', fn () => $this->accounts->update($formerSuper, $lastSuper, $lastSuper->name, User::ROLE_ADMINISTRATOR));
        $this->assertTrue($lastSuper->refresh()->isActive());
    }

    public function test_guard_violation_is_shown_as_notification_in_panel(): void
    {
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('deactivate', $super, ['reason' => 'uji'])
            ->assertNotified('Hanya super admin yang bisa mengubah akun atau peran super admin.');
        $this->assertTrue($super->refresh()->isActive());
        // Aksi nonaktifkan tidak tersedia untuk akun sendiri.
        Livewire::test(ListUsers::class)->assertTableActionHidden('deactivate', $admin);
    }

    // ---------------------------------------------------------------- akun nonaktif

    public function test_deactivated_account_cannot_login_or_use_token_and_can_be_reactivated(): void
    {
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $student = User::factory()->create(['email' => 'peserta@example.com', 'password' => Hash::make('rahasia123'), 'role' => User::ROLE_STUDENT]);
        $oldToken = $this->postJson('/api/v1/auth/login', ['email' => 'peserta@example.com', 'password' => 'rahasia123'])->assertOk()->json('token');
        $this->assertNotNull($student->refresh()->last_login_at);
        $spare = $student->createToken('cadangan')->plainTextToken;

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)->callTableAction('deactivate', $student, ['reason' => 'Permintaan peserta'])->assertNotified('Akun dinonaktifkan.');
        $this->app['auth']->forgetGuards();

        $this->assertFalse($student->refresh()->isActive());
        $this->assertSame(0, $student->tokens()->count());
        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($spare)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'peserta@example.com', 'password' => 'rahasia123'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Akun ini dinonaktifkan. Hubungi admin PPTI untuk mengaktifkannya kembali.');
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login_blocked')->where('auditable_id', $student->id)->exists());

        // Token yang belum dicabut (mis. dibuat di luar alur ini) juga ditolak selama akun nonaktif.
        $student->forceFill(['deactivated_at' => null])->save();
        $live = $student->createToken('lain')->plainTextToken;
        $student->forceFill(['deactivated_at' => now()])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($live)->getJson('/api/v1/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->accounts->activate($admin, $student);
        $this->postJson('/api/v1/auth/login', ['email' => 'peserta@example.com', 'password' => 'rahasia123'])->assertOk();
    }

    public function test_deactivated_admin_loses_panel_access(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $this->accounts->deactivate($super, $admin);

        $this->actingAs($admin->refresh())->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin/export/registrations.csv')->assertForbidden();
    }

    // ---------------------------------------------------------------- audit & email

    public function test_role_status_and_email_actions_are_audited(): void
    {
        Notification::fake();
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $target = $this->user(User::ROLE_STUDENT, ['email_verified_at' => null]);
        $this->actingAs($super);

        Livewire::test(ListUsers::class)
            ->callTableAction('edit', $target, ['name' => 'Nama Diubah', 'role' => User::ROLE_TRAINER, 'extra_roles' => [User::ROLE_ASSESSOR]])
            ->assertHasNoTableActionErrors()
            ->callTableAction('resendVerification', $target)
            ->callTableAction('sendPasswordReset', $target)
            ->callTableAction('deactivate', $target, ['reason' => 'Uji audit']);
        Livewire::test(ListUsers::class)->callTableAction('activate', $target->refresh());

        $target->refresh();
        $this->assertSame('Nama Diubah', $target->name);
        $this->assertSame(User::ROLE_TRAINER, $target->role);
        $this->assertEqualsCanonicalizing([User::ROLE_TRAINER, User::ROLE_ASSESSOR], $target->roleSlugs());
        Notification::assertSentTo($target, VerifyEmailNotification::class);
        Notification::assertSentTo($target, ResetPasswordNotification::class);

        $logs = AuditLog::query()->where('auditable_type', User::class)->where('auditable_id', $target->id)->pluck('action')->all();
        foreach (['user.roles_changed', 'user.name_changed', 'user.verification_resent', 'user.password_reset_sent', 'user.deactivated', 'user.activated'] as $action) {
            $this->assertContains($action, $logs);
        }
        $change = AuditLog::query()->where('action', 'user.roles_changed')->where('auditable_id', $target->id)->first();
        $this->assertSame($super->id, $change->actor_id);
        $this->assertSame([User::ROLE_STUDENT], $change->before['roles']);
        $this->assertEqualsCanonicalizing([User::ROLE_ASSESSOR, User::ROLE_TRAINER], $change->after['roles']);
        $off = AuditLog::query()->where('action', 'user.deactivated')->where('auditable_id', $target->id)->first();
        $this->assertSame('Uji audit', $off->after['reason']);
    }

    // ---------------------------------------------------------------- peserta & alumni

    public function test_member_profile_edit_only_changes_safe_fields_and_status_stays_automatic(): void
    {
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $m = $this->member('Rina Peserta', ['id_number' => '3171000000000001']);
        $this->actingAs($admin);

        Livewire::test(EditMember::class, ['record' => $m->member_number])
            ->fillForm(['phone' => '081234567890', 'company' => 'PT Uji', 'city' => 'Batam'])
            ->call('save')->assertHasNoFormErrors();
        // Field selain yang aman diabaikan meski dikirim lewat state Livewire.
        Livewire::test(EditMember::class, ['record' => $m->member_number])
            ->set('data.status', 'alumni')->set('data.id_number', '999')->set('data.member_number', 'MBR-PALSU')
            ->call('save');

        $m->refresh();
        $this->assertSame('081234567890', $m->phone);
        $this->assertSame('PT Uji', $m->company);
        $this->assertSame('registered', $m->status);
        $this->assertSame('3171000000000001', $m->id_number);
        $this->assertStringStartsWith('MBR-', $m->member_number);
        $this->assertNotSame('MBR-PALSU', $m->member_number);
        $this->assertTrue(AuditLog::query()->where('action', 'Member.updated')->where('auditable_id', $m->id)->exists());
    }

    public function test_member_list_counts_and_filters(): void
    {
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $plc = TrainingPackage::query()->create(['slug' => 'plc', 'title' => 'PLC Dasar', 'status' => 'published']);
        $net = TrainingPackage::query()->create(['slug' => 'ccna', 'title' => 'CCNA', 'status' => 'published']);
        $s1 = $this->schedule('PLC-01', $plc, '2026-09-01', '2026-09-03');
        $s2 = $this->schedule('NET-01', $net, '2026-10-01', '2026-10-03');
        $a = $this->member('Andi', ['company' => 'PT Satu']);
        $b = $this->member('Budi', ['company' => 'PT Dua']);
        $this->enroll($a, $s1, 'completed');
        $this->enroll($b, $s2, 'enrolled');
        $this->actingAs($admin);

        Livewire::test(ListMembers::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertTableColumnStateSet('completed_count', 1, $a)
            ->filterTable('program', $plc->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->resetTableFilters()
            ->filterTable('company', 'PT Dua')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
            ->resetTableFilters()
            ->filterTable('status', 'alumni')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);

        $csv = $this->get('/admin/export/members.csv?company=PT+Satu')->assertOk()->streamedContent();
        $this->assertStringContainsString($a->member_number, $csv);
        $this->assertStringNotContainsString($b->member_number, $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'report.exported')->exists());
    }

    public function test_alumni_list_follows_member_status(): void
    {
        $admin = $this->user(User::ROLE_ADMINISTRATOR);
        $plc = TrainingPackage::query()->create(['slug' => 'plc', 'title' => 'PLC Dasar', 'status' => 'published']);
        $scada = TrainingPackage::query()->create(['slug' => 'scada', 'title' => 'SCADA', 'status' => 'published']);
        $old = $this->schedule('PLC-25', $plc, '2025-05-01', '2025-05-03');
        $new = $this->schedule('SCD-26', $scada, '2026-08-01', '2026-08-03');
        $running = TrainingSchedule::query()->create(['package_id' => $scada->id, 'code' => 'SCD-27', 'start_date' => '2026-11-01', 'end_date' => '2026-12-20', 'status' => 'ongoing', 'quota' => 20]);

        $alumni2025 = $this->member('Alumni Lama', ['company' => 'PT Lama']);
        $e = $this->enroll($alumni2025, $old, 'completed');
        $alumni2026 = $this->member('Alumni Baru');
        $this->enroll($alumni2026, $new, 'completed');
        $active = $this->member('Masih Aktif');
        $this->enroll($active, $running, 'attending');
        $failed = $this->member('Tidak Lulus');
        $this->enroll($failed, $new, 'failed');
        $registered = $this->member('Baru Daftar');

        Certificate::query()->create(['enrollment_id' => $e->id, 'member_id' => $alumni2025->id, 'number' => 'PPTI-UJI-1', 'verification_code' => 'kode-uji-1', 'type' => 'participation', 'status' => 'issued', 'recipient_name' => 'Alumni Lama', 'program_title' => 'PLC Dasar', 'issued_at' => '2025-05-03']);
        Certificate::query()->create(['enrollment_id' => $e->id, 'member_id' => $alumni2025->id, 'number' => 'PPTI-UJI-2', 'verification_code' => 'kode-uji-2', 'type' => 'participation', 'status' => 'issued', 'recipient_name' => 'Alumni Lama', 'program_title' => 'PLC Dasar', 'issued_at' => '2025-05-03', 'valid_until' => '2025-12-31']);

        $this->assertSame('alumni', $alumni2025->refresh()->status);
        $this->assertSame('2025-05-03', $alumni2025->alumni_since->toDateString());
        $this->actingAs($admin);

        Livewire::test(ListAlumni::class)
            ->assertCanSeeTableRecords([$alumni2025, $alumni2026])
            ->assertCanNotSeeTableRecords([$active, $failed, $registered])
            ->assertTableColumnStateSet('active_certificates_count', 1, $alumni2025)
            ->assertTableColumnStateSet('programs', ['PLC Dasar'], $alumni2025)
            ->filterTable('year', '2025')->assertCanSeeTableRecords([$alumni2025])->assertCanNotSeeTableRecords([$alumni2026])
            ->resetTableFilters()
            ->filterTable('program', $scada->id)->assertCanSeeTableRecords([$alumni2026])->assertCanNotSeeTableRecords([$alumni2025])
            ->resetTableFilters()
            ->callTableAction('certificates', $alumni2025)->assertHasNoTableActionErrors();

        $csv = $this->get('/admin/export/alumni.csv?year=2026')->assertOk()->streamedContent();
        $this->assertStringContainsString($alumni2026->member_number, $csv);
        $this->assertStringNotContainsString($alumni2025->member_number, $csv);
        $this->assertStringNotContainsString($active->member_number, $csv);
    }

    private function assertThrowsDomain(string $needle, callable $fn): void
    {
        try {
            $fn();
            $this->fail('DomainException tidak terjadi.');
        } catch (DomainException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, $e->getMessage());
        }
    }
}
