<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondasi tata kelola AI terukur.
 *
 * - ai_insights (kerangka sebelumnya) dilengkapi: subjek, keluaran JSON, skor/keyakinan, status draft|approved|rejected|expired,
 *   alasan penolakan, kedaluwarsa. Keluaran AI hanya pernah disimpan di sini, tidak pernah menulis ke tabel resmi
 *   (kelulusan, nilai, kompetensi, sertifikat, enrollment, invoice, pembayaran).
 * - ai_usage_records: satu baris per panggilan model (atau per penolakan karena batas/jeda), berisi token dan perkiraan biaya.
 * - ai_insight_feedback: umpan balik pengguna "membantu / tidak membantu" per insight, dipakai sebagai metrik.
 * - ai_use_case_settings: tombol jeda admin per use case. Admin hanya bisa MEMATIKAN; menyalakan tetap lewat env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->nullableMorphs('subject');
            $table->foreignId('organization_id')->nullable()->after('use_case')->constrained()->nullOnDelete()
                ->comment('organisasi sponsor yang boleh melihat insight ini (batas privasi perusahaan)');
            $table->json('output')->nullable();
            $table->decimal('score', 8, 4)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('status', 15)->default('draft')->comment('draft|approved|rejected|expired');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->index(['use_case', 'status']);
        });

        Schema::create('ai_usage_records', function (Blueprint $table) {
            $table->id();
            $table->string('use_case', 60);
            $table->string('model', 100)->nullable();
            $table->string('prompt_version', 50)->nullable();
            $table->foreignId('ai_insight_id')->nullable()->constrained('ai_insights')->nullOnDelete();
            $table->string('status', 15)->comment('ok|refused|error|limited|paused');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['use_case', 'created_at']);
        });

        Schema::create('ai_insight_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_insight_id')->constrained('ai_insights')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('helpful');
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['ai_insight_id', 'user_id']);
        });

        Schema::create('ai_use_case_settings', function (Blueprint $table) {
            $table->string('use_case', 60)->primary();
            $table->boolean('paused')->default(false);
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_use_case_settings');
        Schema::dropIfExists('ai_insight_feedback');
        Schema::dropIfExists('ai_usage_records');
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->dropIndex(['use_case', 'status']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropMorphs('subject');
            $table->dropColumn(['output', 'score', 'confidence', 'status', 'rejection_reason', 'expires_at']);
        });
    }
};
