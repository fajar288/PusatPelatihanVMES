<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema Training Program (PRD §5.3) & Schedule (PRD §5.4).
 *
 * Catatan desain:
 * - `training_categories` hierarkis: 8 area kompetensi (beranda, show_on_home) → kategori paket (anak).
 * - `training_schedules` = batch/kelas (PRD §5.4 menyamakan "jadwal" dengan "kelas": kode kelas, tanggal,
 *   jam, lokasi, metode, kuota, trainer, status). Tabel enrollment/peserta menyusul di Phase 2.
 * - Kolom status/mode memakai string (bukan enum DB) agar portabel PostgreSQL/SQLite; validasi di aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('training_categories')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 40)->nullable()->comment('id ikon sprite tema (cpu, bot, factory, ...)');
            $table->boolean('show_on_home')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('training_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('training_categories')->nullOnDelete();
            $table->string('code', 40)->nullable()->unique();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('vendor')->nullable();
            $table->text('excerpt')->nullable();
            $table->json('overview_intro')->nullable()->comment('paragraf pembuka');
            $table->text('objectives')->nullable();
            $table->json('competencies')->nullable();
            $table->json('learning_outcomes')->nullable();
            $table->json('audience')->nullable();
            $table->json('requirements')->nullable();
            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->unsignedSmallInteger('duration_hours')->nullable()->comment('jam pelajaran (JP)');
            $table->string('level', 40)->nullable();
            $table->string('mode', 30)->default('offline')->comment('online|offline|hybrid|online_offline');
            $table->decimal('price', 14, 2)->nullable();
            $table->unsignedSmallInteger('quota')->nullable();
            $table->string('image')->nullable();
            $table->string('certificate')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->string('availability', 30)->default('available')->comment('available|coming_soon|closed');
            $table->string('status', 20)->default('draft')->comment('draft|published|archived');
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'published_at']);
        });

        Schema::create('training_curricula', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('training_packages')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('title')->comment('unit / modul');
            $table->json('topics')->nullable();
            $table->unsignedSmallInteger('duration_hours')->nullable();
            $table->string('method', 60)->nullable();
            $table->text('outcomes')->nullable()->comment('hasil belajar');
            $table->timestamps();
        });

        Schema::create('training_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('training_packages')->cascadeOnDelete();
            $table->string('code', 40)->unique()->comment('kode kelas');
            $table->string('title')->nullable()->comment('override judul; default = judul paket');
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('location')->nullable();
            $table->string('mode', 30)->default('offline');
            $table->unsignedSmallInteger('quota')->nullable();
            $table->unsignedSmallInteger('registered_count')->default(0);
            $table->decimal('price', 14, 2)->nullable()->comment('override harga paket');
            $table->string('trainer_name')->nullable()->comment('sementara string; relasi trainer di Phase 2');
            $table->string('status', 30)->default('draft')
                ->comment('draft|registration_open|registration_closed|confirmed|ongoing|completed|cancelled');
            $table->date('registration_deadline')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_schedules');
        Schema::dropIfExists('training_curricula');
        Schema::dropIfExists('training_packages');
        Schema::dropIfExists('training_categories');
    }
};
