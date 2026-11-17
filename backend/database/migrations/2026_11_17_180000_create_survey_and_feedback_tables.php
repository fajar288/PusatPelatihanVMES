<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survei evaluasi pasca-kelas + analisis umpan balik.
 *
 * - survey_templates: daftar pertanyaan (skala 1-5 atau komentar). Satu template bawaan dibuat otomatis.
 * - survey_responses: satu per enrollment; dibuat saat undangan dikirim (pending), diisi peserta (submitted).
 * - feedback_labels: label manual admin per komentar (sentimen, kategori, tema, perlu tindakan) untuk mengukur
 *   akurasi/F1 klasifikasi AI.
 * - ai_batches: Message Batches yang dikirim ke Claude dan dipantau perintah terjadwal (tanpa worker permanen di cPanel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('questions')->comment('[{key, type: scale|comment, label, required}]');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('survey_templates')->restrictOnDelete();
            $table->foreignId('schedule_id')->constrained('training_schedules')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->unique()->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 15)->default('pending')->comment('pending|submitted');
            $table->json('answers')->nullable()->comment('{key: nilai 1-5 | teks komentar}');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['schedule_id', 'status']);
        });

        Schema::create('feedback_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('survey_responses')->cascadeOnDelete();
            $table->string('question_key', 40);
            $table->string('sentiment', 15);
            $table->string('category', 30);
            $table->string('theme', 120)->nullable();
            $table->boolean('actionable')->default(false);
            $table->foreignId('labeled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['response_id', 'question_key']);
        });

        Schema::create('ai_batches', function (Blueprint $table) {
            $table->id();
            $table->string('use_case', 60);
            $table->string('provider_id', 120)->nullable()->unique();
            $table->string('status', 15)->default('submitted')->comment('submitted|ended|failed');
            $table->json('items')->comment('custom_id → referensi data (ID saja)');
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['use_case', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_batches');
        Schema::dropIfExists('feedback_labels');
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_templates');
    }
};
