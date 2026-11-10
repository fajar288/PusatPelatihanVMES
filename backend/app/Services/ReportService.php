<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use Illuminate\Support\Collection;

/** Laporan operasional (task Phase 2): dataset tabular untuk tampilan admin & ekspor CSV/XLSX. */
class ReportService
{
    public const TYPES = [
        'registrations' => 'Pendaftaran',
        'payments' => 'Pembayaran',
        'schedules' => 'Kelas & kapasitas',
        'enrollments' => 'Peserta & kehadiran',
    ];

    /** @return array{headers: string[], rows: array<int, array<int, mixed>>} */
    public function dataset(string $type, ?string $from = null, ?string $to = null): array
    {
        return match ($type) {
            'registrations' => $this->registrations($from, $to),
            'payments' => $this->payments($from, $to),
            'schedules' => $this->schedules(),
            'enrollments' => $this->enrollments(),
            default => throw new \InvalidArgumentException("Jenis laporan tidak dikenal: $type"),
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
        $q = Payment::query()->with('registration');
        $this->range($q, 'created_at', $from, $to);
        $rows = $q->orderBy('created_at')->get()->map(fn (Payment $p) => [
            $p->invoice_number, $p->registration?->registration_number, $p->registration?->name, (float) $p->amount, "{$p->installment_no}/{$p->installment_total}",
            $p->due_date?->format('Y-m-d'), $p->status, $p->proof_uploaded_at?->format('Y-m-d H:i'), $p->verified_at?->format('Y-m-d H:i'), $p->note,
        ])->all();

        return ['headers' => ['Invoice', 'Registrasi', 'Pendaftar', 'Jumlah', 'Termin', 'Jatuh tempo', 'Status', 'Bukti diunggah', 'Diverifikasi', 'Catatan'], 'rows' => $rows];
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
