<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 tahap 1 — operasional TMS (PRD §5.3, §5.4, §5.7):
 * jenis program & aturan kelulusan paket, trainer/assessor + penugasan kelas, sesi kelas, enrollment,
 * kehadiran, daftar tunggu, riwayat perubahan jadwal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_packages', function (Blueprint $table): void {
            $table->string('program_type', 30)->default('regular')->after('mode')
                ->comment('regular|in_house|private|online|blended|custom|bundling');
            $table->string('certificate_type', 30)->default('participation')->after('certificate')
                ->comment('participation|competency|vendor');
            $table->json('completion_rule')->nullable()->after('certificate_type')
                ->comment('{"min_attendance_pct":80,"min_score":null,"require_assignment":false,"require_posttest":false}');
        });

        Schema::create('trainers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete()->comment('akun login trainer (portal /trainer)');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('photo')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('title')->nullable()->comment('mis. Lead Trainer Networking');
            $table->json('expertise')->nullable();
            $table->text('education')->nullable();
            $table->text('experience')->nullable();
            $table->json('certifications')->nullable();
            $table->text('portfolio')->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_assessor')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_site')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('class_trainers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('training_schedules')->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('trainers')->cascadeOnDelete();
            $table->string('role', 20)->default('lead')->comment('lead|assistant|assessor');
            $table->timestamps();
            $table->unique(['schedule_id', 'trainer_id']);
        });

        Schema::create('class_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('training_schedules')->cascadeOnDelete();
            $table->unsignedSmallInteger('session_no');
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('topic')->nullable();
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'session_no']);
        });

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('training_schedules')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('registrations')->nullOnDelete();
            $table->foreignId('registration_participant_id')->nullable()->constrained('registration_participants')->nullOnDelete();
            $table->string('participant_name');
            $table->string('participant_email')->nullable();
            $table->string('status', 20)->default('enrolled')->comment('enrolled|attending|completed|failed|dropped');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['schedule_id', 'status']);
        });

        Schema::create('attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->string('status', 10)->default('present')->comment('present|absent|late|excused');
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'enrollment_id']);
        });

        Schema::create('waitlists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('training_schedules')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('registrations')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->unsignedSmallInteger('participants_count')->default(1);
            $table->string('status', 20)->default('waiting')->comment('waiting|offered|converted|cancelled');
            $table->timestamp('offered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['schedule_id', 'status']);
        });

        Schema::create('schedule_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('training_schedules')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->unsignedSmallInteger('notified_count')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_changes');
        Schema::dropIfExists('waitlists');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('class_trainers');
        Schema::dropIfExists('trainers');
        Schema::table('training_packages', function (Blueprint $table): void {
            $table->dropColumn(['program_type', 'certificate_type', 'completion_rule']);
        });
    }
};
