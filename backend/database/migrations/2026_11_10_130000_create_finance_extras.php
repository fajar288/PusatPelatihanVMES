<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2 tahap 2 — finance lanjutan (PRD §5.11): kode promo, diskon, termin pembayaran. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('type', 10)->default('percent')->comment('percent|fixed');
            $table->decimal('value', 14, 2);
            $table->decimal('min_total', 14, 2)->nullable();
            $table->decimal('max_discount', 14, 2)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->foreignId('package_id')->nullable()->constrained('training_packages')->nullOnDelete()->comment('batasi ke paket tertentu');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('registrations', function (Blueprint $table): void {
            $table->string('promo_code', 40)->nullable()->after('discount');
            $table->string('discount_note')->nullable()->after('promo_code');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('installment_no')->default(1)->after('amount');
            $table->unsignedTinyInteger('installment_total')->default(1)->after('installment_no');
            $table->string('description')->nullable()->after('installment_total');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['installment_no', 'installment_total', 'description']);
        });
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn(['promo_code', 'discount_note']);
        });
        Schema::dropIfExists('promo_codes');
    }
};
