<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5A — fondasi data enterprise (PRD Roadmap 5 §4.1, §13, §20.1, §20.6, §20.12).
 * - organizations: akun perusahaan/instansi (corporate account).
 * - organization_units: departemen / cost center / proyek (hierarki opsional).
 * - employment_relationships: hubungan kerja Member ↔ Organization (ACTIVE/INACTIVE), terpisah dari Member (§18).
 * - corporate_users: User ↔ Organization + peran perusahaan + batas unit opsional (§20.12, §20.14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique()->comment('ORG-0001');
            $table->string('legal_name');
            $table->string('display_name')->nullable();
            $table->string('normalized_name')->index()->comment('nama ternormalisasi untuk pencocokan (tanpa PT/CV/Tbk, huruf kecil)');
            $table->string('industry', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('tax_id', 40)->nullable()->comment('NPWP');
            $table->string('invoice_name')->nullable();
            $table->text('invoice_address')->nullable();
            $table->string('invoice_email')->nullable();
            $table->string('logo')->nullable();
            $table->string('status', 20)->default('active')->comment('active|inactive|suspended');
            $table->boolean('needs_review')->default(false)->comment('dibuat otomatis (backfill/pendaftaran kolektif) — perlu diperiksa admin');
            $table->string('source', 30)->default('admin')->comment('admin|registration|backfill|self_signup');
            $table->json('settings')->nullable()->comment('kebijakan per organisasi, mis. exit_access');
            $table->text('notes')->nullable()->comment('catatan internal VMES — tidak tampil ke perusahaan');
            $table->timestamps();
        });

        Schema::create('organization_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('name');
            $table->string('type', 20)->default('department')->comment('department|cost_center|project');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'type', 'name']);
        });

        Schema::create('employment_relationships', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique()->comment('EMP-000001');
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('employee_id', 60)->nullable()->comment('nomor induk karyawan di perusahaan');
            $table->string('position')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 10)->default('active')->comment('active|inactive');
            $table->string('end_reason', 30)->nullable()->comment('resigned|transferred|contract_ended|other');
            $table->string('source', 30)->default('admin')->comment('admin|corporate_portal|bulk_upload|invitation|registration|api');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'employee_id']);
            $table->index(['member_id', 'status']);
        });

        Schema::create('corporate_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->comment('owner|hr_admin|supervisor|finance|viewer');
            $table->foreignId('unit_id')->nullable()->constrained('organization_units')->nullOnDelete()->comment('batas akses unit; kosong = seluruh organisasi');
            $table->string('status', 20)->default('active')->comment('active|disabled');
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_users');
        Schema::dropIfExists('employment_relationships');
        Schema::dropIfExists('organization_units');
        Schema::dropIfExists('organizations');
    }
};
