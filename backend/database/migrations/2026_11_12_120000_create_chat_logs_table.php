<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log chatbot publik (Measurable AI): pertanyaan & jawaban, model + versi prompt, token, latensi,
 * status (answered/unresolved/refused/error), umpan balik pengunjung & tinjauan admin. IP disimpan sebagai hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('conversation_id', 64)->index();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->string('status', 20)->default('answered')->index()->comment('answered|unresolved|refused|error');
            $table->string('model', 60)->nullable();
            $table->string('prompt_version', 30)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('feedback', 10)->nullable()->comment('up|down — dari pengunjung');
            $table->string('review', 20)->nullable()->comment('correct|incorrect|needs_info — tinjauan admin');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_logs');
    }
};
