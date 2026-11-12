<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5B — Corporate Portal MVP (PRD Roadmap 5 §4.2, §4.3, §20.3, §20.4, §20.13).
 * - training_requests: permintaan training perusahaan berbasis kebutuhan (bukan daftar nama), status §4.2.
 * - request_seats: kursi 01..N. Kursi yang dipesan TIDAK membuat Member (§20.3, AC §20.22).
 * - participant_invitations: tautan undangan bertoken (hash) — peserta melengkapi data sendiri, verifikasi email + consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique()->comment('REQ-YYYYMM-0001');
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained('training_packages')->restrictOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('training_schedules')->nullOnDelete()->comment('batch yang ditetapkan VMES saat SCHEDULED');
            $table->foreignId('unit_id')->nullable()->constrained('organization_units')->nullOnDelete()->comment('departemen / cost center sponsor');
            $table->string('delivery', 20)->default('onsite')->comment('onsite|vmes|online|hybrid');
            $table->date('target_date')->nullable();
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('seats');
            $table->text('trainer_requirements')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft')->comment('draft|submitted|quoted|approval|approved|paid|scheduled|in_progress|completed|cancelled');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('price_per_seat', 14, 2)->nullable();
            $table->decimal('quote_discount', 14, 2)->default(0);
            $table->decimal('quote_total', 14, 2)->nullable();
            $table->date('quote_valid_until')->nullable();
            $table->text('quote_notes')->nullable();
            $table->foreignId('quoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quoted_at')->nullable();
            $table->foreignId('quote_accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quote_accepted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignId('paid_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->text('internal_notes')->nullable()->comment('catatan internal VMES — tidak tampil ke perusahaan');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('request_seats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seat_no');
            $table->string('status', 20)->default('reserved')->comment('reserved|invited|assigned|enrolled');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employment_id')->nullable()->constrained('employment_relationships')->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('participant_name')->nullable();
            $table->string('participant_email')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignment_method', 20)->nullable()->comment('manual|bulk|invitation|employee');
            $table->timestamps();
            $table->unique(['training_request_id', 'seat_no']);
        });

        Schema::create('participant_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique()->comment('sha256 dari token; token mentah hanya ada di email');
            $table->foreignId('training_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('employee_id', 60)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('position')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending|accepted|revoked|expired');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consent_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_invitations');
        Schema::dropIfExists('request_seats');
        Schema::dropIfExists('training_requests');
    }
};
