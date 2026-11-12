<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use Illuminate\Support\Collection;

/** Laporan operasional (task Phase 2): dataset tabular untuk tampilan admin & ekspor CSV/XLSX. */
class ReportService
{
    public const TYPES = [
        'registrations' => 'Pendaftaran',
        'payments' => 'Invoice & pembayaran',
        'schedules' => 'Kelas & kapasitas',
        'enrollments' => 'Peserta & kehadiran',
    ];

    /** Ekspor Dashboard Eksekutif (Phase 5) — memakai seluruh filter dashboard; butuh izin analytics.view. */
    public const EXECUTIVE_TYPES = [
        'executive' => 'Ringkasan eksekutif',
        'at-risk' => 'Peserta berisiko',
        'trainers' => 'Kinerja trainer',
    ];

    /**
     * @param  array<string, mixed>  $filters  filter Dashboard Eksekutif (lihat AnalyticsService::FILTER_KEYS)
     * @return array{headers: string[], rows: array<int, array<int, mixed>>}
     */
    public function dataset(string $type, ?string $from = null, ?string $to = null, array $filters = []): array
    {
        return match ($type) {
            'registrations' => $this->registrations($from, $to),
            'payments' => $this->payments($from, $to),
            'schedules' => $this->schedules(),
            'enrollments' => $this->enrollments(),
            'executive', 'at-risk', 'trainers' => $this->executive($type, ['from' => $from, 'to' => $to] + $filters),
            default => throw new \InvalidArgumentException("Jenis laporan tidak dikenal: $type"),
        };
    }

    private function executive(string $type, array $filters): array
    {
        $analytics = app(AnalyticsService::class);
        $d = $analytics->dashboard($filters);

        return match ($type) {
            'executive' => ['headers' => ['Bagian', 'Indikator', 'Nilai'], 'rows' => $analytics->flatten($d)],
            'at-risk' => [
                'headers' => ['Peserta', 'Email', 'Kelas', 'Program', 'Status', 'Kehadiran %', 'Progres LMS %', 'Rata-rata kuis', 'Alasan'],
                'rows' => array_map(fn ($r) => [$r['name'], $r['email'], $r['class'], $r['program'], $r['status'], $r['attendance'], $r['lms'], $r['quiz'], implode('; ', $r['reasons'])], $d['learning']['at_risk']),
            ],
            'trainers' => [
                'headers' => ['Trainer', 'Jabatan', 'Kelas', 'Sesi terlaksana', 'Sesi terjadwal', 'Logbook', 'Logbook tervalidasi', 'Peserta', 'Penilaian', 'Skor kompetensi %', 'Completion rate %'],
                'rows' => array_map(fn ($r) => [$r['name'], $r['title'], $r['classes'], $r['sessions_held'], $r['sessions_planned'], $r['logbooks'], $r['logbooks_validated'], $r['participants'], $r['assessments'], $r['avg_competency'], $r['completion_rate']], $d['trainers']['rows']),
            ],
        };
    }

    /** Ringkasan untuk halaman Laporan. @return array<string, mixed> */
    public function overview(): array
    {
        $regs = Registration::query()->get(['status']);
        $byStatus = [];
        foreach (RegistrationStatus::cases() as $st) {
            $byStatus[$st->value] = ['label' => $st->label(), 'count' => $regs->where('status', $st)->count()];
        }

        return [
            'registrations_total' => $regs->count(),
            'registrations_by_status' => $byStatus,
            'upcoming_classes' => TrainingSchedule::query()->upcoming()->count(),
            'enrollments_active' => Enrollment::query()->whereIn('status', ['enrolled', 'attending'])->count(),
            'revenue' => app(FinanceService::class)->revenueSummary(),
        ];
    }

    private function registrations(?string $from, ?string $to): array
    {
        $q = Registration::query()->with('schedule.package');
        $this->range($q, 'created_at', $from, $to);
        $rows = $q->orderBy('created_at')->get()->map(fn (Registration $r) => [
            $r->registration_number, $r->created_at?->format('Y-m-d'), $r->type, $r->status->label(), $r->name, $r->email, $r->phone, $r->company,
            $r->schedule?->code, $r->schedule?->package?->title, $r->participants_count, (float) $r->price_quoted, (float) $r->discount, $r->promo_code, (float) $r->total,
        ])->all();

        return ['headers' => ['Nomor', 'Tanggal', 'Jenis', 'Status', 'Nama', 'Email', 'Telepon', 'Perusahaan', 'Kelas', 'Paket', 'Peserta', 'Harga', 'Diskon', 'Promo', 'Total'], 'rows' => $rows];
    }

    private function payments(?string $from, ?string $to): array
    {
        $q = Invoice::query()->with(['registration', 'trainingRequest', 'organization', 'transactions']);
        $this->range($q, 'created_at', $from, $to);
        $rows = $q->orderBy('created_at')->get()->map(function (Invoice $i) {
            $last = $i->transactions->first();

            return [
                $i->invoice_number, $i->created_at->format('Y-m-d'), $i->sourceLabel(), $i->bill_to_name, $i->organization?->code,
                (float) $i->subtotal, (float) $i->discount, (float) $i->tax_amount, (float) $i->total, (float) $i->amount_paid, (float) $i->amount_refunded,
                "{$i->installment_no}/{$i->installment_total}", $i->due_date?->format('Y-m-d'), $i->status, $i->paid_at?->format('Y-m-d H:i'),
                $last?->code, $last?->status, $last?->submitted_at?->format('Y-m-d H:i'), $last?->verified_at?->format('Y-m-d H:i'), $i->settlement_note,
            ];
        })->all();

        return ['headers' => ['Invoice', 'Tanggal', 'Sumber', 'Ditagihkan ke', 'Organisasi', 'Subtotal', 'Diskon', 'Pajak', 'Total', 'Terbayar', 'Direfund', 'Termin',
            'Jatuh tempo', 'Status', 'Lunas', 'Transaksi terakhir', 'Status transaksi', 'Bukti diunggah', 'Diverifikasi', 'Catatan selisih'], 'rows' => $rows];
    }

    private function schedules(): array
    {
        $rows = TrainingSchedule::query()->with('package')->withCount(['enrollments', 'waitlists'])->orderBy('start_date')->get()->map(fn (TrainingSchedule $s) => [
            $s->code, $s->package?->title, $s->start_date->format('Y-m-d'), $s->end_date->format('Y-m-d'), $s->city, $s->mode, $s->status,
            $s->quota, $s->registered_count, $s->remaining(), $s->enrollments_count, $s->waitlists_count, $s->trainer_name,
        ])->all();

        return ['headers' => ['Kode', 'Paket', 'Mulai', 'Selesai', 'Kota', 'Metode', 'Status', 'Kuota', 'Terdaftar', 'Sisa', 'Peserta', 'Daftar tunggu', 'Trainer'], 'rows' => $rows];
    }

    private function enrollments(): array
    {
        $rows = Enrollment::query()->with(['schedule.package', 'member', 'registration'])->orderBy('schedule_id')->get()->map(fn (Enrollment $e) => [
            $e->schedule?->code, $e->schedule?->package?->title, $e->participant_name, $e->participant_email, $e->member?->member_number,
            $e->registration?->registration_number, $e->status, $e->attendancePercent(), $e->final_score !== null ? (float) $e->final_score : null,
        ])->all();

        return ['headers' => ['Kode kelas', 'Paket', 'Peserta', 'Email', 'No. member', 'Registrasi', 'Status', 'Kehadiran %', 'Nilai akhir'], 'rows' => $rows];
    }

    private function range($q, string $col, ?string $from, ?string $to): void
    {
        if ($from) {
            $q->whereDate($col, '>=', $from);
        }
        if ($to) {
            $q->whereDate($col, '<=', $to);
        }
    }

    /** Konversi dataset ke koleksi baris asosiatif (untuk tabel Filament). */
    public function toAssoc(array $dataset): Collection
    {
        return collect($dataset['rows'])->map(fn ($row) => array_combine($dataset['headers'], $row));
    }
}
