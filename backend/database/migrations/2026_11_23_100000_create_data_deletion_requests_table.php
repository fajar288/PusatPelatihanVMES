<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan penghapusan data pribadi dari peserta (pending -> approved/rejected -> completed/cancelled) dan kolom
 * sertifikat untuk menyamarkan nama penerima: sertifikat tetap sah, nama tidak tampil di verifikasi publik, dan
 * pemberi kerja masih bisa mencocokkan nama yang tertera di sertifikat lewat sidik (HMAC) nama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->id();
            // Akun tetap ada (dianonimkan) setelah selesai, jadi relasi ke users tetap utuh.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Label tanpa identitas (nomor member atau "Akun #id") untuk daftar admin setelah data dihapus.
            $table->string('account_label', 60);
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('auto_rejected')->default(false);
            $table->text('reason')->nullable();
            $table->text('admin_note')->nullable();
            $table->json('blockers')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('due_at')->index();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->string('recipient_name_hash', 64)->nullable();
            $table->timestamp('recipient_anonymized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['recipient_name_hash', 'recipient_anonymized_at']);
        });
        Schema::dropIfExists('data_deletion_requests');
    }
};
