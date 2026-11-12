<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening tujuan pembayaran manual (keputusan 2026-11-12: tanpa payment gateway).
 * Peserta transfer ke salah satu rekening / Virtual Account statis ini, lalu unggah bukti di portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->default('bank_transfer')->comment('bank_transfer|virtual_account');
            $table->string('bank_name', 60);
            $table->string('account_number', 40);
            $table->string('account_name', 120);
            $table->text('instructions')->nullable()->comment('Petunjuk bayar, mis. langkah VA di m-banking');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};
