<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5F: master kompetensi & target per posisi untuk competency gap dasar (PRD Roadmap 5 §8.2, §20.15, §20.16).
 * - competencies: master kompetensi (sebelumnya hanya teks bebas di rubrik/paket).
 * - package_competencies: program yang membangun kompetensi + level yang dicapai lulusan (bukti "training" & sumber rekomendasi).
 * - rubric_criteria.competency_id: kriteria rubrik yang mengukur kompetensi (bukti "assessment").
 * - competency_targets: level kompetensi yang dibutuhkan per posisi di satu organisasi (posisi dicocokkan dengan
 *   employment_relationships.position yang dinormalisasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique()->comment('mis. PLC, HMI, SCADA');
            $table->string('name');
            $table->string('category', 80)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('package_competencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('training_packages')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(70)->comment('level (0-100) yang dicapai peserta yang lulus program');
            $table->timestamps();
            $table->unique(['package_id', 'competency_id']);
            $table->index(['competency_id', 'level']);
        });

        Schema::table('rubric_criteria', function (Blueprint $table): void {
            $table->foreignId('competency_id')->nullable()->after('competency')->constrained()->nullOnDelete();
        });

        Schema::create('competency_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('position')->comment('nama posisi seperti ditulis perusahaan');
            $table->string('position_key')->comment('posisi ternormalisasi (huruf kecil, spasi tunggal) untuk dicocokkan dengan employment');
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('required_level')->comment('level minimal (1-100)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'position_key', 'competency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_targets');
        Schema::table('rubric_criteria', fn (Blueprint $table) => $table->dropConstrainedForeignId('competency_id'));
        Schema::dropIfExists('package_competencies');
        Schema::dropIfExists('competencies');
    }
};
