<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5C — Transaksi (PRD Roadmap 5 §5, §13) dengan pembayaran MANUAL (tanpa payment gateway, keputusan 2026-11-12).
 *
 * - invoices: menggantikan tabel `payments` lama (satu baris = satu tagihan/termin). Item, subtotal, diskon, pajak
 *   (tarif dari pengaturan keuangan, bawaan 0%), total, jatuh tempo, terbayar, dikembalikan. Sumber: pendaftaran
 *   (individu/kolektif) ATAU Corporate Training Request. Status: pending|paid|failed|expired|refunded|cancelled.
 * - payment_transactions: setiap bukti transfer yang diunggah (pending) dan hasil verifikasi finance (paid) / penolakan
 *   (failed). Menyimpan rekening tujuan, tanggal transfer, nominal dinyatakan vs diterima, verifikator & waktu.
 * - payment_refunds: refund manual — alasan, penyetuju (orang lain), waktu, referensi transfer balik. Uang dikembalikan
 *   di luar sistem; sistem hanya mencatat.
 * - bank_statement_imports / bank_statement_lines: mutasi rekening CSV opsional untuk rekonsiliasi.
 *
 * Data lama dipindahkan: unpaid→pending, submitted→pending + transaksi pending, verified→paid + transaksi paid,
 * rejected→failed + transaksi failed, refunded→refunded + transaksi paid + refund selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 24)->unique()->comment('INV-YYYYMM-0001');
            $table->foreignId('registration_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('training_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->comment('perusahaan penanggung tagihan (kolektif / training request)');
            $table->string('status', 20)->default('pending')->comment('pending|paid|failed|expired|refunded|cancelled');
            $table->string('bill_to_name');
            $table->string('bill_to_email')->nullable();
            $table->string('bill_to_company')->nullable();
            $table->text('bill_to_address')->nullable();
            $table->string('bill_to_tax_id', 40)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->string('discount_note')->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_label', 40)->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_refunded', 14, 2)->default(0);
            $table->unsignedTinyInteger('installment_no')->default(1);
            $table->unsignedTinyInteger('installment_total')->default(1);
            $table->string('description')->nullable();
            $table->text('notes')->nullable()->comment('catatan untuk pembayar (tampil di invoice)');
            $table->timestamp('issued_at')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->text('settlement_note')->nullable()->comment('catatan finance atas selisih nominal saat dinyatakan lunas');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique()->comment('TRX-YYYYMM-00001');
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20)->default('bank_transfer')->comment('bank_transfer|virtual_account|cash|other');
            $table->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination')->nullable()->comment('salinan rekening tujuan saat transaksi dicatat');
            $table->string('payer_name')->nullable();
            $table->decimal('amount', 14, 2)->comment('nominal yang dinyatakan pembayar');
            $table->decimal('amount_received', 14, 2)->nullable()->comment('nominal yang diterima menurut finance');
            $table->decimal('difference', 14, 2)->nullable()->comment('diterima − sisa tagihan saat verifikasi (+ lebih bayar, − kurang bayar)');
            $table->date('transfer_date')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending|paid|failed|cancelled');
            $table->string('source', 20)->default('member')->comment('member|corporate|admin|migration');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('note')->nullable()->comment('catatan pembayar');
            $table->text('review_note')->nullable()->comment('catatan / alasan finance');
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique()->comment('RFD-YYYYMM-0001');
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->string('status', 20)->default('requested')->comment('requested|approved|rejected|completed');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete()->comment('penyetuju / penolak');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('transfer_reference')->nullable()->comment('referensi transfer balik (di luar sistem)');
            $table->date('transferred_on')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('bank_statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('file_name');
            $table->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->date('posted_on');
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->string('fingerprint', 64)->unique()->comment('sha256 tanggal|nominal|keterangan|referensi — baris yang sama tidak diimpor dua kali');
            $table->string('status', 20)->default('unmatched')->comment('unmatched|matched|ignored');
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_method', 10)->nullable()->comment('auto|manual');
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'posted_on']);
        });

        $this->migrateLegacyPayments();
        Schema::dropIfExists('payments');
    }

    public function down(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 24)->unique();
            $table->decimal('amount', 14, 2);
            $table->unsignedTinyInteger('installment_no')->default(1);
            $table->unsignedTinyInteger('installment_total')->default(1);
            $table->string('description')->nullable();
            $table->date('due_date')->nullable();
            $table->string('method', 30)->default('bank_transfer');
            $table->string('proof_path')->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->string('status', 20)->default('unpaid');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // kembalikan tagihan pendaftaran (invoice training request tidak punya padanan di skema lama)
        DB::table('invoices')->whereNotNull('registration_id')->orderBy('id')->get()->each(function ($inv): void {
            $tx = DB::table('payment_transactions')->where('invoice_id', $inv->id)->orderByDesc('id')->first();
            $status = match ($inv->status) {
                'paid' => 'verified',
                'refunded' => 'refunded',
                'failed' => 'rejected',
                default => $tx && $tx->status === 'pending' ? 'submitted' : 'unpaid',
            };
            DB::table('payments')->insert([
                'registration_id' => $inv->registration_id, 'invoice_number' => $inv->invoice_number, 'amount' => $inv->total,
                'installment_no' => $inv->installment_no, 'installment_total' => $inv->installment_total, 'description' => $inv->description,
                'due_date' => $inv->due_date, 'method' => $tx->method ?? 'bank_transfer', 'proof_path' => $tx->proof_path ?? null,
                'proof_uploaded_at' => $tx->submitted_at ?? null, 'status' => $status, 'verified_by' => $tx->verified_by ?? null,
                'verified_at' => $tx->verified_at ?? null, 'note' => $tx->review_note ?? $tx->note ?? null,
                'created_at' => $inv->created_at, 'updated_at' => $inv->updated_at,
            ]);
        });

        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statement_imports');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }

    private function migrateLegacyPayments(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }
        $trxSeq = [];
        DB::table('payments')->orderBy('id')->get()->each(function ($p) use (&$trxSeq): void {
            $reg = DB::table('registrations')->where('id', $p->registration_id)->first();
            $status = match ($p->status) {
                'verified' => 'paid',
                'rejected' => 'failed',
                'refunded' => 'refunded',
                default => 'pending',
            };
            $paid = in_array($p->status, ['verified', 'refunded'], true) ? $p->amount : 0;
            $invoiceId = DB::table('invoices')->insertGetId([
                'invoice_number' => $p->invoice_number,
                'registration_id' => $p->registration_id,
                'organization_id' => $reg->organization_id ?? null,
                'status' => $status,
                'bill_to_name' => $reg->name ?? '-',
                'bill_to_email' => $reg->email ?? null,
                'bill_to_company' => $reg->company ?? null,
                'subtotal' => $p->amount, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => $p->amount,
                'amount_paid' => $paid,
                'amount_refunded' => $p->status === 'refunded' ? $p->amount : 0,
                'installment_no' => $p->installment_no ?? 1,
                'installment_total' => $p->installment_total ?? 1,
                'description' => $p->description ?? null,
                'issued_at' => $p->created_at,
                'due_date' => $p->due_date,
                'paid_at' => $paid > 0 ? ($p->verified_at ?? $p->updated_at) : null,
                'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
            ]);
            DB::table('invoice_items')->insert([
                'invoice_id' => $invoiceId,
                'description' => $p->description ?: 'Biaya pelatihan'.($reg ? ' — '.$reg->registration_number : ''),
                'quantity' => 1, 'unit_price' => $p->amount, 'amount' => $p->amount, 'sort' => 0,
                'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
            ]);

            if ($p->status === 'unpaid' && ! $p->proof_path) {
                return;
            }
            $at = $p->proof_uploaded_at ?? $p->created_at;
            $prefix = 'TRX-'.date('Ym', strtotime((string) $at)).'-';
            $trxSeq[$prefix] = ($trxSeq[$prefix] ?? 0) + 1;
            $txStatus = match ($p->status) {
                'verified', 'refunded' => 'paid',
                'rejected' => 'failed',
                default => 'pending',
            };
            $txId = DB::table('payment_transactions')->insertGetId([
                'code' => $prefix.str_pad((string) $trxSeq[$prefix], 5, '0', STR_PAD_LEFT),
                'invoice_id' => $invoiceId,
                'method' => $p->method ?: 'bank_transfer',
                'payer_name' => $reg->name ?? null,
                'amount' => $p->amount,
                'amount_received' => $txStatus === 'paid' ? $p->amount : null,
                'difference' => $txStatus === 'paid' ? 0 : null,
                'proof_path' => $p->proof_path,
                'status' => $txStatus,
                'source' => 'migration',
                'submitted_by' => $reg->user_id ?? null,
                'submitted_at' => $at,
                'verified_by' => $txStatus === 'pending' ? null : $p->verified_by,
                'verified_at' => $txStatus === 'pending' ? null : $p->verified_at,
                'review_note' => $txStatus === 'failed' ? $p->note : null,
                'note' => $txStatus === 'failed' ? null : $p->note,
                'created_at' => $at, 'updated_at' => $p->updated_at,
            ]);

            if ($p->status === 'refunded') {
                DB::table('payment_refunds')->insert([
                    'code' => 'RFD-'.date('Ym', strtotime((string) $p->updated_at)).'-'.str_pad((string) $txId, 4, '0', STR_PAD_LEFT),
                    'invoice_id' => $invoiceId, 'payment_transaction_id' => $txId, 'amount' => $p->amount,
                    'reason' => 'Refund tercatat sebelum modul refund (migrasi data 5C).', 'status' => 'completed',
                    'requested_at' => $p->updated_at, 'decided_at' => $p->updated_at, 'completed_at' => $p->updated_at,
                    'created_at' => $p->updated_at, 'updated_at' => $p->updated_at,
                ]);
            }
        });
    }
};
