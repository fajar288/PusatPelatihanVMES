<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5E — API enterprise (PRD Roadmap 5 §7, §7.1, §20.21):
 * - klien API terikat organisasi + bertindak atas nama satu pengguna perusahaan (peran & batas unitnya ikut berlaku),
 * - kunci idempotensi (`Idempotency-Key`) untuk POST penting,
 * - jejak audit mencatat klien API yang memicu perubahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('organization')->constrained()->nullOnDelete()
                ->comment('klien enterprise: semua data dibatasi organisasi ini (tidak bisa diganti lewat URL/API)');
            $table->foreignId('corporate_user_id')->nullable()->after('organization_id')->constrained()->nullOnDelete()
                ->comment('pengguna perusahaan yang diwakili; peran & batas unitnya membatasi klien');
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('key', 255);
            $table->string('method', 10);
            $table->string('route', 120)->nullable();
            $table->string('request_hash', 64)->comment('sha256 method + path + body; key yang sama dengan isi berbeda ditolak');
            $table->string('status', 12)->default('processing')->comment('processing|completed');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->index();
            $table->unique(['api_client_id', 'key']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('api_client_id')->nullable()->after('actor_name')->constrained()->nullOnDelete()
                ->comment('diisi bila aksi dipicu lewat API Publik');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('api_client_id');
        });
        Schema::dropIfExists('api_idempotency_keys');
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corporate_user_id');
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
