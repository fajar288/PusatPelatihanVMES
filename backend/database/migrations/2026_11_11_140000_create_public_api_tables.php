<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API Publik (Phase 5, PRD §4.1 Core Services / §10 REST API): klien integrasi pihak ketiga dengan API key + scope,
 * log permintaan, webhook keluar, dan penanda pendaftaran yang dibuat lewat API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('organization')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('description')->nullable();
            $table->string('key_prefix', 20)->comment('awalan key untuk identifikasi visual, mis. ppti_ab12cd34');
            $table->string('key_hash', 64)->unique()->comment('sha256 dari API key; key asli hanya ditampilkan sekali');
            $table->json('scopes');
            $table->unsignedInteger('rate_limit')->default(60)->comment('permintaan per menit');
            $table->json('allowed_ips')->nullable();
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable()->comment('terenkripsi');
            $table->json('webhook_events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('key_rotated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path');
            $table->string('route', 120)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['api_client_id', 'created_at']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            $table->json('payload');
            $table->string('status', 20)->default('pending')->comment('pending|delivered|failed');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['api_client_id', 'created_at']);
        });

        Schema::table('registrations', function (Blueprint $table): void {
            $table->foreignId('api_client_id')->nullable()->after('source')->constrained()->nullOnDelete();
            $table->string('external_ref', 100)->nullable()->after('api_client_id')->comment('referensi sistem klien API (idempoten)');
            $table->unique(['api_client_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropUnique(['api_client_id', 'external_ref']);
            $table->dropConstrainedForeignId('api_client_id');
            $table->dropColumn('external_ref');
        });
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_clients');
    }
};
