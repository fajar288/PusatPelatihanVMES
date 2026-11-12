<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5A.
 * - identity_reviews: antrean tinjauan identitas (PRD Roadmap 5 §20.5 "Data meragukan → review queue,
 *   jangan otomatis membuat akun"), juga kandidat yang perlu dikonfirmasi (nomor HP / email belum terverifikasi).
 * - sponsorship_transfers: alur resmi pengalihan sponsor enrollment (§20.20, R7/R8) — permintaan, otorisasi,
 *   sponsor lama & baru, tanggal efektif, alasan; berlaku per tanggal efektif; tidak bisa diubah setelah diterapkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_reviews', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('subject');
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('training_schedules')->nullOnDelete();
            $table->string('reason', 30)->comment('not_found|phone_match|unverified_email|employee_id_match|conflict|incomplete|name_mismatch');
            $table->json('input')->comment('data peserta yang dicocokkan: nama, email, HP, nomor member, employee ID');
            $table->json('candidates')->nullable()->comment('[{member_id, member_number, name, matched_by}]');
            $table->string('status', 20)->default('pending')->comment('pending|resolved|dismissed');
            $table->string('resolution', 20)->nullable()->comment('linked|created|dismissed');
            $table->foreignId('resolved_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('sponsorship_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique()->comment('STR-YYYYMM-0001');
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_organization_id')->nullable()->constrained('organizations')->restrictOnDelete()->comment('kosong = sebelumnya SELF');
            $table->foreignId('to_organization_id')->nullable()->constrained('organizations')->restrictOnDelete()->comment('kosong = menjadi SELF');
            $table->foreignId('to_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->date('effective_date');
            $table->text('reason');
            $table->string('authorization_reference')->nullable()->comment('surat/memo/email persetujuan pihak berwenang');
            $table->string('status', 20)->default('requested')->comment('requested|approved|rejected|applied|cancelled');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_transfers');
        Schema::dropIfExists('identity_reviews');
    }
};
