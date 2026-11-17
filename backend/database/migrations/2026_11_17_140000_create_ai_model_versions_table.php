<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versi model statistik per use case AI (mis. bobot skor risiko early warning hasil kalibrasi dari kelas yang sudah selesai).
 * Setiap insight mencatat versi model yang dipakai (ai_insights.model_version). Hanya satu versi aktif per use case;
 * tanpa versi aktif, use case memakai bobot awal bawaan kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('use_case', 60);
            $table->string('version', 60)->unique();
            $table->json('params')->comment('bobot/parameter model');
            $table->json('metrics')->nullable()->comment('hasil evaluasi saat kalibrasi (holdout vs baseline)');
            $table->unsignedInteger('training_size')->default(0);
            $table->boolean('is_active')->default(false);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['use_case', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_versions');
    }
};
