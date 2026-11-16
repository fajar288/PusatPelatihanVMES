<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kerangka tata kelola AI (PRD Roadmap 5 §9.1, tahap 5G): setiap keluaran AI nanti dicatat sebagai insight dengan use case,
 * versi model, versi prompt, referensi data (ID + hash, bukan salinan data pribadi), dan peninjau. Tabel sengaja kosong -
 * belum ada fitur AI yang menulis ke sini. Tahap 5I.1 melengkapinya (subjek, keluaran, status, alasan penolakan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->string('use_case', 60)->index();
            $table->string('model_version', 100)->nullable();
            $table->string('prompt_version', 50)->nullable();
            $table->json('data_reference')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
