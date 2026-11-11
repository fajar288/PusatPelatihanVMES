<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Peran & izin dasar (PRD §5.6, task 1E). Idempoten. */
class RolesSeeder extends Seeder
{
    public const ROLES = [
        'super_admin' => 'Super Admin',
        'administrator' => 'Administrator',
        'management' => 'Management',
        'marketing' => 'Marketing',
        'finance' => 'Finance',
        'training_coordinator' => 'Training Coordinator',
        'trainer' => 'Trainer',
        'assessor' => 'Assessor',
        'student' => 'Student',
        'alumni' => 'Alumni',
    ];

    public const PERMISSIONS = [
        'cms.manage' => ['Kelola konten website', 'cms', ['administrator', 'management', 'marketing']],
        'registrations.view' => ['Lihat pendaftaran', 'registrations', ['administrator', 'management', 'marketing', 'finance', 'training_coordinator']],
        'registrations.manage' => ['Proses & ubah status pendaftaran', 'registrations', ['administrator', 'management', 'marketing', 'training_coordinator']],
        'payments.verify' => ['Verifikasi pembayaran', 'finance', ['administrator', 'finance']],
        'training.manage' => ['Kelola paket & jadwal', 'training', ['administrator', 'management', 'training_coordinator']],
        'users.manage' => ['Kelola pengguna & peran', 'users', ['administrator']],
        'reports.view' => ['Lihat laporan', 'reports', ['administrator', 'management', 'finance']],
        'lms.manage' => ['Kelola materi LMS (course, modul, lesson, kuis, tugas)', 'lms', ['administrator', 'management', 'training_coordinator']],
        'monitoring.manage' => ['Kelola logbook, rubrik & penilaian kompetensi (termasuk buka kunci)', 'monitoring', ['administrator', 'management', 'training_coordinator']],
        'certificates.manage' => ['Kelola template & draf sertifikat, tetapkan kelulusan', 'certificates', ['administrator', 'management', 'training_coordinator']],
        'certificates.issue' => ['Terbitkan, cabut & ganti sertifikat (pejabat berwenang)', 'certificates', ['administrator', 'management']],
        'analytics.view' => ['Lihat dashboard eksekutif & analitik manajemen', 'reports', ['administrator', 'management']],
        'api.manage' => ['Kelola klien API publik, API key & webhook', 'integrations', ['administrator']],
        'portal.access' => ['Akses portal member', 'portal', ['student', 'alumni']],
        'trainer.portal' => ['Akses portal trainer', 'portal', ['trainer', 'assessor']],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $slug => $name) {
            Role::query()->updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
        foreach (self::PERMISSIONS as $slug => [$name, $group, $roles]) {
            $perm = Permission::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group]);
            $perm->roles()->sync(Role::query()->whereIn('slug', $roles)->pluck('id'));
        }

        // sinkronkan user_roles dari kolom role utama
        User::query()->each(function (User $u): void {
            $role = Role::query()->where('slug', $u->role)->first();
            if ($role) {
                $u->roles()->syncWithoutDetaching([$role->id => ['created_at' => now()]]);
            }
        });
    }
}
