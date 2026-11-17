<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekomendasi training.
 *
 * - package_prerequisites: program X mensyaratkan program Y sudah diluluskan (diisi admin di form Paket training).
 *   Dipakai rekomendasi ("Anda lulus PLC Dasar, ini kelanjutannya") dan untuk tidak menyarankan program yang
 *   prasyaratnya belum dipenuhi.
 * - ai_recommendation_events: impresi dan klik per rekomendasi yang ditampilkan (untuk CTR). Konversi dihitung saat
 *   evaluasi dari pendaftaran / enrollment / permintaan training yang sudah ada, tanpa mengubah tabel itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('training_packages')->cascadeOnDelete();
            $table->foreignId('prerequisite_id')->constrained('training_packages')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['package_id', 'prerequisite_id']);
            $table->index('prerequisite_id');
        });

        Schema::create('ai_recommendation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_insight_id')->constrained('ai_insights')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('training_packages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context', 15)->comment('member|corporate');
            $table->string('event', 15)->comment('impression|click');
            $table->unsignedTinyInteger('rank')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['event', 'created_at']);
            $table->index(['ai_insight_id', 'package_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommendation_events');
        Schema::dropIfExists('package_prerequisites');
    }
};
