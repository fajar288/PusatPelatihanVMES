<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 tahap 1 — LMS (PRD §5.8):
 * Program (paket) → Kelas (jadwal) → Course → Module → Lesson → Resource, kuis & soal, tugas & pengumpulan,
 * learning progress per enrollment, forum diskusi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('training_packages')->cascadeOnDelete()->comment('program pemilik course');
            $table->foreignId('schedule_id')->nullable()->constrained('training_schedules')->nullOnDelete()->comment('bila diisi: hanya untuk kelas ini');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('available_before_class')->default(false)->comment('materi bisa dibuka sebelum kelas mulai');
            $table->unsignedSmallInteger('access_days_after')->nullable()->comment('akses setelah kelas berakhir (hari); null = selamanya');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['package_id', 'is_published']);
        });

        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('module_id')->constrained('course_modules')->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 20)->default('text')->comment('text|document|video|link|quiz|assignment');
            $table->longText('body')->nullable()->comment('HTML materi / instruksi');
            $table->json('settings')->nullable()->comment('konfigurasi per tipe: video_url, quiz, assignment, link');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_published')->default(true);
            $table->date('available_from')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['module_id', 'sort_order']);
        });

        Schema::create('lesson_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('type', 10)->default('file')->comment('file|link');
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('url')->nullable();
            $table->boolean('allow_download')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('type', 15)->default('single')->comment('single|multiple|true_false|short');
            $table->text('question');
            $table->json('options')->nullable()->comment('[{"key":"a","text":"..."}]');
            $table->json('answer')->nullable()->comment('kunci jawaban: ["a"] | ["a","c"] | ["true"] | ["teks"]');
            $table->text('explanation')->nullable();
            $table->decimal('points', 5, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->json('answers')->nullable()->comment('{question_id: jawaban}');
            $table->json('detail')->nullable()->comment('{question_id: {correct, points}}');
            $table->decimal('score', 7, 2)->nullable();
            $table->decimal('max_score', 7, 2)->nullable();
            $table->unsignedSmallInteger('percent')->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamps();
            $table->index(['lesson_id', 'enrollment_id']);
        });

        Schema::create('assignment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->text('text')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('status', 15)->default('submitted')->comment('submitted|graded|returned');
            $table->timestamp('submitted_at');
            $table->decimal('score', 7, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->unique(['lesson_id', 'enrollment_id']);
        });

        Schema::create('lesson_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('status', 15)->default('in_progress')->comment('in_progress|completed');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('video_position')->default(0)->comment('detik terakhir (resume)');
            $table->unsignedSmallInteger('video_percent')->default(0);
            $table->unsignedInteger('time_spent_seconds')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'lesson_id']);
        });

        Schema::create('discussion_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('discussion_posts')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_trainer')->default(false)->comment('ditulis trainer/admin');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->index(['course_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_posts');
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('lesson_resources');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('courses');
    }
};
