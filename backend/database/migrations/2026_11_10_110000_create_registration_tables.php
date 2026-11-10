<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1E — registrasi online, member, peran/izin, pembayaran awal, audit log (PRD §5.5, §5.6, §5.11, §9).
 *
 * - `users.role` (migrasi sebelumnya) tetap sebagai peran utama; `user_roles` memungkinkan multi-peran.
 * - `registrations` = pendaftar (individu/kolektif) dengan nomor otomatis & status PRD §5.5.
 * - `members` = identitas digital berkelanjutan (satu akun = satu member, nomor member).
 * - `payments` = tagihan + bukti transfer + verifikasi finance (subset PRD §5.11 yang dibutuhkan alur status).
 * - `audit_logs` = jejak perubahan (actor, action, model, before/after).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name');
            $table->string('group', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('member_number', 20)->unique()->comment('MBR-YYYY-000001');
            $table->string('phone', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('id_number', 32)->nullable()->comment('NIK — hanya bila diperlukan sertifikasi (PRD §9 minimisasi data)');
            $table->string('company')->nullable();
            $table->string('position')->nullable();
            $table->string('education', 60)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('photo')->nullable();
            $table->json('communication_pref')->nullable()->comment('{"email":true,"whatsapp":true}');
            $table->string('status', 20)->default('registered')->comment('registered|active|alumni|inactive');
            $table->timestamps();
        });

        Schema::create('registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('registration_number', 24)->unique()->comment('REG-YYYYMM-0001');
            $table->foreignId('schedule_id')->constrained('training_schedules')->restrictOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('training_packages')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('individual')->comment('individual|corporate');
            $table->string('status', 30)->default('pending')
                ->comment('pending|under_review|waiting_payment|payment_verification|confirmed|rejected|cancelled');
            // data pendaftar / PIC
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30);
            $table->string('company')->nullable();
            $table->string('position')->nullable();
            $table->unsignedSmallInteger('participants_count')->default(1);
            $table->text('notes')->nullable();
            $table->json('documents')->nullable()->comment('path dokumen pendukung');
            $table->boolean('consent')->default(false);
            // biaya
            $table->decimal('price_quoted', 14, 2)->nullable();
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->nullable();
            // review
            $table->text('status_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('source', 20)->default('web');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at']);
            $table->index('email');
        });

        Schema::create('registration_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('position')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 24)->unique()->comment('INV-YYYYMM-0001');
            $table->decimal('amount', 14, 2);
            $table->date('due_date')->nullable();
            $table->string('method', 30)->default('bank_transfer');
            $table->string('proof_path')->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->string('status', 20)->default('unpaid')->comment('unpaid|submitted|verified|rejected|refunded');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 60);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('registration_participants');
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('members');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
