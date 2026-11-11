<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 tahap 2 — Training Logbook & Progress Monitoring (PRD §5.9):
 * rubrik penilaian per program, logbook per pertemuan (+ catatan per peserta & dokumentasi),
 * penilaian kompetensi individual per enrollment. Catatan tervalidasi dikunci; perubahan tercatat di audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_rubrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('training_packages')->cascadeOnDelete()->comment('program pemilik rubrik');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('competent_threshold')->default(70)->comment('persen minimal dinyatakan kompeten');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['package_id', 'is_active']);
        });

        Schema::create('rubric_criteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rubric_id')->constrained('assessment_rubrics')->cascadeOnDelete();
            $table->string('code', 30)->nullable()->comment('theory|practice|troubleshooting|tools|assignment|independence|custom');
            $table->string('name');
            $table->string('competency')->nullable()->comment('unit kompetensi yang diukur');
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(1);
            $table->unsignedTinyInteger('max_score')->default(4);
            $table->json('levels')->nullable()->comment('[{"score":1,"label":"Belum kompeten","description":"..."}]');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('training_logbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('training_schedules')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('class_sessions')->nullOnDelete()->comment('pertemuan (sesi kelas)');
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->string('topic');
            $table->text('activities')->nullable()->comment('aktivitas pembelajaran');
            $table->json('competencies')->nullable()->comment('kompetensi yang dilatih');
            $table->text('practice')->nullable()->comment('praktik yang dilakukan');
            $table->text('observations')->nullable()->comment('observasi trainer');
            $table->text('obstacles')->nullable()->comment('kendala');
            $table->text('notes')->nullable()->comment('catatan trainer');
            $table->json('documentation')->nullable()->comment('[{"path","name","mime","size"}]');
            $table->string('status', 15)->default('draft')->comment('draft|validated');
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->index(['schedule_id', 'date']);
        });

        Schema::create('logbook_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logbook_id')->constrained('training_logbooks')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->string('practice_status', 15)->nullable()->comment('completed|partial|not_done');
            $table->text('observation')->nullable()->comment('observasi/catatan untuk peserta ini');
            $table->timestamps();
            $table->unique(['logbook_id', 'enrollment_id']);
        });

        Schema::create('competency_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->unique()->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('rubric_id')->constrained('assessment_rubrics')->cascadeOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_percent', 5, 2)->nullable();
            $table->boolean('is_competent')->nullable();
            $table->text('summary')->nullable()->comment('catatan/umpan balik trainer');
            $table->text('recommendation')->nullable();
            $table->string('status', 15)->default('draft')->comment('draft|validated');
            $table->timestamp('assessed_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::create('assessment_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_id')->constrained('competency_assessments')->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('rubric_criteria')->cascadeOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['assessment_id', 'criterion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_scores');
        Schema::dropIfExists('competency_assessments');
        Schema::dropIfExists('logbook_entries');
        Schema::dropIfExists('training_logbooks');
        Schema::dropIfExists('rubric_criteria');
        Schema::dropIfExists('assessment_rubrics');
    }
};
