<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5H — indeks untuk kueri dashboard & laporan (Dashboard Eksekutif, KPI §8.1, Corporate Portal,
 * API enterprise, rekonsiliasi). PostgreSQL TIDAK membuat indeks otomatis untuk foreign key, jadi kolom yang sering
 * difilter/di-join (member, sponsor, invoice, organisasi, tanggal bayar) diberi indeks eksplisit. MySQL sudah membuat
 * indeks untuk foreign key; indeks yang kolomnya sudah tercakup dilewati (Schema::hasIndex) agar migrasi aman diulang.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    public const INDEXES = [
        // scope sponsor + batas unit (CorporateAccess, CorporateReporting), riwayat peserta, segmentasi corporate/self (KPI)
        'enrollments' => [['sponsor_organization_id', 'sponsor_unit_id'], ['member_id', 'status'], ['type', 'status'], ['registration_id']],
        // kehadiran per peserta (persentase hadir, at-risk, KPI attendance)
        'attendances' => [['enrollment_id', 'status']],
        // invoice organisasi (portal & API /payments), pendapatan per tanggal bayar (Dashboard Eksekutif, AOV)
        'invoices' => [['organization_id', 'status'], ['registration_id'], ['training_request_id'], ['paid_at']],
        'payment_transactions' => [['invoice_id', 'status']],
        'payment_refunds' => [['invoice_id', 'status']],
        // funnel & konversi, pendaftaran kolektif organisasi, riwayat member
        'registrations' => [['schedule_id', 'status'], ['organization_id', 'status'], ['member_id']],
        'training_schedules' => [['package_id', 'start_date'], ['end_date']],
        'training_requests' => [['package_id'], ['schedule_id']],
        'request_seats' => [['member_id'], ['enrollment_id']],
        // bukti kompetensi tervalidasi terbaru (competency gap)
        'competency_assessments' => [['status', 'validated_at']],
        // keanggotaan perusahaan per user (dicek di setiap request corporate)
        'corporate_users' => [['user_id', 'status']],
        // penelusuran audit per aksi & per aktor
        'audit_logs' => [['action', 'created_at'], ['actor_id', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $this->name($table, $columns)));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($table, $this->name($table, $columns))) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($this->name($table, $columns)));
                }
            }
        }
    }

    /** @param  list<string>  $columns */
    private function name(string $table, array $columns): string
    {
        return 'r5h_'.$table.'_'.implode('_', $columns).'_index';
    }
};
