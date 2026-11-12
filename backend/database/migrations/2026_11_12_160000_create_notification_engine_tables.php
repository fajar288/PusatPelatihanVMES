<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5D — Notification Engine (PRD Roadmap 5 §6, §6.1).
 * - `notification_events`: business event dicatat SEKALI (dedupe_key unik) + pemicu (aktor/sistem) + jadwal.
 * - `notification_deliveries`: satu baris per penerima × kanal, status Queued → Sent → Delivered → Read / Failed, retry.
 * - Template berversi: `notification_templates.audience` + `version` + `wa_body`, riwayat di `notification_template_versions`.
 * - `notification_logs` menjadi riwayat percobaan per pengiriman (delivery_id + attempt).
 * - Preferensi WhatsApp per kategori.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->string('audience', 20)->default('participant')->after('key')->comment('participant|corporate|trainer|admin');
            $table->unsignedInteger('version')->default(1)->after('audience');
            $table->text('wa_body')->nullable()->after('mail_body')->comment('isi WhatsApp; kosong = judul + isi singkat');
            $table->foreignId('updated_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
        });
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'audience']);
        });

        Schema::create('notification_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('notification_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('title');
            $table->text('body');
            $table->string('mail_subject')->nullable();
            $table->text('mail_body')->nullable();
            $table->text('wa_body')->nullable();
            $table->json('channels');
            $table->boolean('is_active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['template_id', 'version']);
        });

        // versi 1 untuk template yang sudah ada
        foreach (DB::table('notification_templates')->get() as $t) {
            DB::table('notification_template_versions')->insert([
                'template_id' => $t->id, 'version' => 1, 'name' => $t->name, 'title' => $t->title, 'body' => $t->body,
                'mail_subject' => $t->mail_subject, 'mail_body' => $t->mail_body, 'wa_body' => null, 'channels' => $t->channels,
                'is_active' => $t->is_active, 'created_at' => now(),
            ]);
        }

        // Template peserta lama yang di PRD §6 juga dikirim lewat WhatsApp: tambah kanal + isi WA sebagai versi baru.
        // Isi yang sudah diedit admin dipertahankan; hanya kanal & wa_body yang ditambahkan.
        $waBodies = [
            'registration.received' => "Halo {name}, pendaftaran *{number}* untuk {program} ({class}) sudah kami terima dan sedang diproses.\n\nPantau statusnya di portal: {link}",
            'registration.status_changed' => "Halo {name}, status pendaftaran *{number}* ({program}) kini *{status}*. {note}\n\nDetail: {link}",
            'schedule.changed' => "Halo {name}, ada perubahan jadwal *{program}* ({class}): {changes}. {reason}\n\nDetail: {link}",
            'class.reminder' => 'Halo {name}, kelas *{program}* ({class}) dimulai *besok*, {date} pukul {time} di {location}. Sampai jumpa!',
            'certificate.issued' => 'Selamat {name}! Sertifikat *{program}* nomor {number} sudah terbit. Unduh di portal: {link}',
        ];
        foreach (DB::table('notification_templates')->whereIn('key', array_keys($waBodies))->get() as $t) {
            $channels = json_decode((string) $t->channels, true) ?: [];
            if (in_array('whatsapp', $channels, true)) {
                continue;
            }
            $channels[] = 'whatsapp';
            DB::table('notification_templates')->where('id', $t->id)->update(['channels' => json_encode($channels), 'wa_body' => $waBodies[$t->key], 'version' => 2]);
            DB::table('notification_template_versions')->insert([
                'template_id' => $t->id, 'version' => 2, 'name' => $t->name, 'title' => $t->title, 'body' => $t->body,
                'mail_subject' => $t->mail_subject, 'mail_body' => $t->mail_body, 'wa_body' => $waBodies[$t->key], 'channels' => json_encode($channels),
                'is_active' => $t->is_active, 'created_at' => now(),
            ]);
        }

        Schema::create('notification_events', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->index()->comment('mis. payment.success, class.reminder_h7');
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable()->comment('variabel template + url');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()->comment('null = sistem/terjadwal');
            $table->string('dedupe_key', 160)->nullable()->unique()->comment('business event hanya dibuat sekali');
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedInteger('deliveries_count')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('audience', 20)->comment('participant|corporate|trainer|admin');
            $table->string('channel', 20)->comment('database|mail|webpush|whatsapp');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_key', 160)->comment('audiens + akun/alamat + organisasi; satu orang dikirimi sekali per event');
            $table->string('recipient')->nullable()->comment('email / nomor WA / user#id');
            $table->string('recipient_name')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->comment('scope perusahaan (audience corporate)');
            $table->json('content')->comment('isi yang dirender saat dibuat: title, body, url, mail_subject, mail_body, wa_body');
            $table->string('status', 12)->default('queued')->comment('queued|sent|delivered|read|failed|cancelled');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(4);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->uuid('notification_id')->nullable()->index()->comment('baris notifications untuk kanal aplikasi');
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['event_id', 'recipient_key']);
            $table->index(['user_id', 'channel']);
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->foreignId('delivery_id')->nullable()->after('id')->constrained('notification_deliveries')->nullOnDelete();
            $table->unsignedTinyInteger('attempt')->nullable()->after('delivery_id');
        });

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->boolean('whatsapp')->default(true)->after('push');
        });

        // Lonceng notifikasi panel admin (Filament) memfilter `data->format`; di PostgreSQL kolom harus bertipe JSON.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }
        Schema::table('notification_preferences', fn (Blueprint $table) => $table->dropColumn('whatsapp'));
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_id');
            $table->dropColumn('attempt');
        });
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_template_versions');
        DB::table('notification_templates')->where('audience', '!=', 'participant')->delete();
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropUnique(['key', 'audience']);
            $table->unique(['key']);
        });
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['audience', 'version', 'wa_body']);
        });
    }
};
