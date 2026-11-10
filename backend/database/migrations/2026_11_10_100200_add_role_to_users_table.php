<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peran dasar pengguna (PRD §5.6 / task 1E). Sementara satu kolom string; tabel roles/permissions
 * penuh (super_admin, administrator, management, marketing, finance, training_coordinator, trainer,
 * assessor, student, alumni) menyusul di Phase 1E. Dipakai untuk membatasi akses panel admin Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 40)->default('student')->after('email')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
