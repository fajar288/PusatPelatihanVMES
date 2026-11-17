<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status akun untuk menu admin Pengguna: akun nonaktif (deactivated_at terisi) tidak bisa login atau memakai token API.
 * last_login_at diisi saat login berhasil (panel admin maupun API). Data akun yang sudah ada tidak diubah: semua tetap aktif.
 *
 * deactivated_by sengaja tanpa constraint foreign key: di SQLite, menambah foreign key ke tabel yang sudah ada membuat
 * tabel users dibangun ulang, dan di dalam transaksi hal itu bisa memicu ON DELETE tabel lain yang merujuk users.
 * Kolom ini hanya catatan siapa yang menonaktifkan (jejak lengkap ada di audit log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->unsignedBigInteger('deactivated_by')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deactivated_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['deactivated_at', 'deactivated_by', 'last_login_at']);
        });
    }
};
