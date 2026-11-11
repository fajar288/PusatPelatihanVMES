<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Certificate Management & Alumni (PRD §5.10, §5.6):
 * template sertifikat (penerbit, penandatangan, masa berlaku, format nomor), sertifikat per enrollment
 * dengan nomor unik & status draft → pending_validation → issued → revoked/replaced, berkas PDF + payload QR.
 * Riwayat penerbitan dicatat di audit_logs (auditable = Certificate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->default('participation')->comment('participation|competency|vendor');
            $table->string('title')->default('SERTIFIKAT')->comment('judul besar pada sertifikat');
            $table->string('subtitle')->nullable()->comment('mis. "Diberikan kepada"');
            $table->text('body')->nullable()->comment('kalimat utama; placeholder {name} {program} {class_code} {start_date} {end_date} {city} {hours} {score}');
            $table->string('issuer_name')->default('PPTI - VMES Training Center');
            $table->string('issuer_title')->nullable()->comment('mis. Lembaga Pelatihan Kerja');
            $table->string('issuer_city', 80)->nullable()->default('Batam');
            $table->string('signatory_name')->nullable();
            $table->string('signatory_title')->nullable();
            $table->string('signature_image')->nullable();
            $table->string('second_signatory_name')->nullable();
            $table->string('second_signatory_title')->nullable();
            $table->string('second_signature_image')->nullable();
            $table->string('logo_image')->nullable();
            $table->string('background_image')->nullable();
            $table->string('accent_color', 9)->default('#004B23');
            $table->string('orientation', 10)->default('landscape')->comment('landscape|portrait');
            $table->string('number_format', 80)->default('PPTI-{TYPE}-{YYYY}{MM}-{SEQ}')->comment('{TYPE} {YYYY} {MM} {SEQ}');
            $table->unsignedSmallInteger('validity_months')->nullable()->comment('masa berlaku; kosong = tanpa batas');
            $table->text('footer_text')->nullable();
            $table->boolean('show_competencies')->default(true)->comment('cetak daftar kompetensi/kurikulum di halaman 2');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'is_active']);
        });

        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 60)->unique();
            $table->string('verification_code', 16)->unique()->comment('kode acak pada QR; nomor tetap publik');
            $table->foreignId('template_id')->nullable()->constrained('certificate_templates')->nullOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('training_schedules')->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('training_packages')->nullOnDelete();
            $table->string('type', 20)->default('participation')->comment('participation|competency|vendor');
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->string('program_title');
            $table->string('class_code', 40)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('city', 80)->nullable();
            $table->unsignedSmallInteger('hours')->nullable()->comment('jumlah jam pelatihan (JP)');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('predicate', 40)->nullable()->comment('mis. Kompeten / Sangat Baik');
            $table->json('competencies')->nullable()->comment('daftar unit kompetensi / materi yang dicetak');
            $table->string('issuer_name')->nullable();
            $table->string('signatory_name')->nullable();
            $table->string('signatory_title')->nullable();
            $table->date('issued_at')->nullable()->comment('tanggal pada sertifikat');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('draft')->comment('draft|pending_validation|issued|revoked|replaced');
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_on')->nullable()->comment('waktu penerbitan (sistem)');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->foreignId('replaces_id')->nullable()->constrained('certificates')->nullOnDelete()->comment('sertifikat lama yang digantikan');
            $table->foreignId('replaced_by_id')->nullable()->constrained('certificates')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['enrollment_id', 'status']);
            $table->index(['member_id', 'status']);
        });

        Schema::table('training_packages', function (Blueprint $table): void {
            $table->foreignId('certificate_template_id')->nullable()->after('certificate_type')->constrained('certificate_templates')->nullOnDelete();
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->date('alumni_since')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('alumni_since');
        });
        Schema::table('training_packages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('certificate_template_id');
        });
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_templates');
    }
};
