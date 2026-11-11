<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification & Communication (PRD §5.12, Phase 5): notifikasi aplikasi (tabel `notifications` bawaan Laravel),
 * template pesan, preferensi pengguna per kategori, log terkirim/gagal semua kanal, dan langganan web push (PWA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique()->comment('kunci peristiwa, mis. class.reminder');
            $table->string('category', 30)->comment('registration|schedule|learning|certificate|announcement');
            $table->string('name');
            $table->string('title')->comment('judul notifikasi aplikasi/push, placeholder {var}');
            $table->text('body')->comment('isi singkat notifikasi aplikasi/push');
            $table->string('mail_subject')->nullable();
            $table->text('mail_body')->nullable()->comment('isi email (paragraf dipisah baris kosong); kosong = pakai body');
            $table->json('channels')->comment('database|mail|webpush');
            $table->json('placeholders')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->boolean('mail')->default(true);
            $table->boolean('push')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });

        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('notifiable');
            $table->string('notification', 80)->comment('kunci template atau nama kelas notifikasi');
            $table->string('category', 30)->nullable();
            $table->string('channel', 20)->comment('database|mail|webpush');
            $table->string('recipient')->nullable();
            $table->string('status', 10)->comment('sent|failed');
            $table->string('subject')->nullable();
            $table->text('error')->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['dedupe_key', 'user_id']);
            $table->index(['channel', 'status']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notifications');
    }
};
