<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Rekonsiliasi finance (PRD Roadmap 5 §5 "Reconciliation"): mencocokkan transaksi pembayaran manual dengan invoice dan,
 * opsional, dengan mutasi rekening (CSV/XLSX). Pencocokan otomatis hanya bila kandidatnya tepat satu; sisanya manual.
 */
class ReconciliationService
{
    /** Toleransi tanggal (hari) antara tanggal transfer yang dinyatakan dan tanggal mutasi. */
    public const DATE_TOLERANCE = 3;

    private const COLUMNS = [
        'date' => ['tanggal', 'tgl', 'date', 'tanggal_transaksi', 'posting_date', 'tgl_transaksi'],
        'description' => ['keterangan', 'deskripsi', 'description', 'uraian', 'remark', 'berita'],
        'amount' => ['nominal', 'jumlah', 'amount', 'kredit', 'credit', 'mutasi', 'cr'],
        'debit' => ['debit', 'debet', 'db'],
        'reference' => ['referensi', 'reference', 'ref', 'no_referensi', 'no_ref'],
    ];

    /**
     * Impor mutasi: hanya baris kredit (uang masuk). Baris yang sudah pernah diimpor (sidik jari sama) dilewati.
     *
     * @return array{import: BankStatementImport, imported: int, skipped: int, matched: int, errors: list<string>}
     */
    public function import(string $path, string $fileName, User $actor, ?int $accountId = null): array
    {
        [$rows, $errors] = $this->readRows($path, $fileName);
        if ($rows === [] && $errors === []) {
            throw ValidationException::withMessages(['file' => 'Berkas tidak berisi baris mutasi. Kolom wajib: tanggal, keterangan, nominal (kredit).']);
        }

        return DB::transaction(function () use ($rows, $errors, $fileName, $actor, $accountId) {
            $import = BankStatementImport::query()->create(['file_name' => $fileName, 'payment_account_id' => $accountId, 'uploaded_by' => $actor->id]);
            $created = collect();
            $skipped = 0;
            foreach ($rows as $row) {
                $fingerprint = hash('sha256', implode('|', [$row['posted_on'], number_format($row['amount'], 2, '.', ''), Str::lower($row['description']), Str::lower($row['reference'] ?? '')]));
                if (BankStatementLine::query()->where('fingerprint', $fingerprint)->exists()) {
                    $skipped++;

                    continue;
                }
                $created->push($import->lines()->create($row + ['fingerprint' => $fingerprint, 'status' => 'unmatched']));
            }
            $matched = $this->autoMatch($created, $actor);
            $import->update(['rows_count' => $created->count(), 'skipped_count' => $skipped, 'matched_count' => $matched]);
            AuditLog::record('reconciliation.statement_imported', $import, null, ['file' => $fileName, 'rows' => $created->count(), 'skipped' => $skipped, 'matched' => $matched], $actor);

            return ['import' => $import, 'imported' => $created->count(), 'skipped' => $skipped, 'matched' => $matched, 'errors' => $errors];
        });
    }

    /** @param  Collection<int, BankStatementLine>  $lines */
    public function autoMatch(Collection $lines, ?User $actor = null): int
    {
        $n = 0;
        foreach ($lines as $line) {
            if ($line->status !== 'unmatched') {
                continue;
            }
            $candidates = $this->candidates($line)->get();
            if ($candidates->count() === 1) {
                $this->link($line, $candidates->first(), $actor, 'auto');
                $n++;
            }
        }

        return $n;
    }

    /** Transaksi (pending/terverifikasi) yang belum tertaut mutasi dengan nominal sama & tanggal dekat, atau nomor invoice/transaksi disebut di keterangan. */
    public function candidates(BankStatementLine $line): Builder
    {
        $amount = number_format((float) $line->amount, 2, '.', '');
        $from = $line->posted_on->copy()->subDays(self::DATE_TOLERANCE)->toDateString();
        $to = $line->posted_on->copy()->addDays(self::DATE_TOLERANCE)->toDateString();
        $text = Str::upper((string) $line->description.' '.(string) $line->reference);
        preg_match_all('/(INV-\d{6}-\d{4,}|TRX-\d{6}-\d{5,})/', $text, $m);
        $codes = array_unique($m[1] ?? []);

        return PaymentTransaction::query()->whereIn('status', ['pending', 'paid'])->whereDoesntHave('statementLine')
            ->where(function (Builder $q) use ($amount, $from, $to, $codes): void {
                $q->where(fn (Builder $w) => $w->where(fn (Builder $a) => $a->where('amount', $amount)->orWhere('amount_received', $amount))
                    ->whereBetween('transfer_date', [$from, $to]));
                if ($codes) {
                    $q->orWhereIn('code', $codes)->orWhereHas('invoice', fn (Builder $i) => $i->whereIn('invoice_number', $codes));
                }
            })->with('invoice');
    }

    public function match(BankStatementLine $line, PaymentTransaction $tx, User $actor): BankStatementLine
    {
        if ($line->status === 'matched') {
            throw ValidationException::withMessages(['line' => 'Baris mutasi ini sudah dicocokkan. Lepaskan dulu bila ingin mengganti.']);
        }
        if ($tx->statementLine()->exists()) {
            throw ValidationException::withMessages(['payment_transaction_id' => "Transaksi {$tx->code} sudah tertaut ke baris mutasi lain."]);
        }

        return $this->link($line, $tx, $actor, 'manual');
    }

    public function unmatch(BankStatementLine $line, User $actor): BankStatementLine
    {
        $before = ['status' => $line->status, 'transaction' => $line->transaction?->code];
        $line->update(['status' => 'unmatched', 'payment_transaction_id' => null, 'match_method' => null, 'matched_by' => null, 'matched_at' => null]);
        AuditLog::record('reconciliation.line_unmatched', $line, $before, ['status' => 'unmatched'], $actor);

        return $line;
    }

    public function ignore(BankStatementLine $line, User $actor, ?string $note = null): BankStatementLine
    {
        $line->update(['status' => 'ignored', 'payment_transaction_id' => null, 'matched_by' => $actor->id, 'matched_at' => now()]);
        AuditLog::record('reconciliation.line_ignored', $line, null, ['status' => 'ignored', 'note' => $note], $actor);

        return $line;
    }

    /**
     * Ringkasan selisih untuk halaman rekonsiliasi.
     *
     * @return array{underpaid: int, overpaid: int, pending: int, unmatched_lines: int, unmatched_amount: float, pending_without_statement: int}
     */
    public function summary(): array
    {
        $open = Invoice::query()->where('status', 'pending')->where('amount_paid', '>', 0);

        return [
            'underpaid' => (clone $open)->count(),
            'overpaid' => PaymentTransaction::query()->where('status', 'paid')->where('difference', '>', 0)->count(),
            'pending' => PaymentTransaction::query()->where('status', 'pending')->count(),
            'unmatched_lines' => BankStatementLine::query()->where('status', 'unmatched')->count(),
            'unmatched_amount' => (float) BankStatementLine::query()->where('status', 'unmatched')->sum('amount'),
            'pending_without_statement' => PaymentTransaction::query()->where('status', 'pending')->whereDoesntHave('statementLine')->count(),
        ];
    }

    private function link(BankStatementLine $line, PaymentTransaction $tx, ?User $actor, string $method): BankStatementLine
    {
        $line->update(['status' => 'matched', 'payment_transaction_id' => $tx->id, 'match_method' => $method, 'matched_by' => $actor?->id, 'matched_at' => now()]);
        $diff = round((float) $line->amount - (float) ($tx->amount_received ?? $tx->amount), 2);
        AuditLog::record('reconciliation.line_matched', $line, null, ['transaction' => $tx->code, 'invoice' => $tx->invoice?->invoice_number, 'method' => $method, 'difference' => $diff], $actor);

        return $line;
    }

    /** @return array{0: list<array{line_no:int, posted_on:string, description:string, amount:float, reference:?string}>, 1: list<string>} */
    private function readRows(string $path, string $fileName): array
    {
        if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'xlsx') {
            $reader = new XlsxReader;
        } else {
            // ekspor bank sering diawali baris judul → tebak pemisah dari beberapa baris pertama
            $fh = fopen($path, 'r');
            $head = '';
            for ($i = 0; $i < 8 && ($line = fgets($fh)) !== false; $i++) {
                $head .= $line;
            }
            fclose($fh);
            $counts = [';' => substr_count($head, ';'), "\t" => substr_count($head, "\t"), ',' => substr_count($head, ',')];
            arsort($counts);
            $opts = new CsvOptions;
            $opts->FIELD_DELIMITER = (string) array_key_first($counts);
            $reader = new CsvReader($opts);
        }
        $reader->open($path);
        $rows = [];
        $errors = [];
        $map = null;
        $lineNo = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $lineNo++;
                $cells = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : trim((string) $v), $row->toArray());
                if ($map === null) {
                    $map = $this->headerMap($cells);
                    if (! isset($map['date'], $map['amount'])) {
                        $map = null;   // baris judul bank di atas header — cari header di baris berikutnya
                        if ($lineNo > 15) {
                            break 2;
                        }
                    }

                    continue;
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                $amount = $this->parseAmount($cells[$map['amount']] ?? '');
                if (isset($map['debit']) && $this->parseAmount($cells[$map['debit']] ?? '') > 0 && $amount <= 0) {
                    continue;   // uang keluar
                }
                if ($amount <= 0) {
                    continue;
                }
                $date = $this->parseDate($cells[$map['date']] ?? '');
                if (! $date) {
                    $errors[] = "Baris {$lineNo}: tanggal \"".($cells[$map['date']] ?? '').'" tidak dikenali.';

                    continue;
                }
                $rows[] = [
                    'line_no' => $lineNo, 'posted_on' => $date, 'amount' => $amount,
                    'description' => isset($map['description']) ? Str::limit($cells[$map['description']] ?? '', 500, '') : '',
                    'reference' => isset($map['reference']) ? (($cells[$map['reference']] ?? '') ?: null) : null,
                ];
                if (count($rows) > 2000) {
                    throw ValidationException::withMessages(['file' => 'Maksimal 2.000 baris per unggahan.']);
                }
            }
            break;
        }
        $reader->close();
        if ($map === null) {
            throw ValidationException::withMessages(['file' => 'Header tidak dikenali. Kolom wajib: tanggal dan nominal/kredit (opsional: keterangan, referensi, debit).']);
        }

        return [$rows, $errors];
    }

    /** @param  list<string>  $cells @return array<string, int> */
    private function headerMap(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $h) {
            $key = Str::of($h)->replaceMatches('/^\x{FEFF}/u', '')->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
            foreach (self::COLUMNS as $col => $aliases) {
                if (! isset($map[$col]) && in_array($key, $aliases, true)) {
                    $map[$col] = $i;
                }
            }
        }

        return $map;
    }

    /** "1.500.000,00", "1,500,000.00", "1500000", "Rp 1.500.000", "1.500.000 CR" → 1500000.0 */
    public function parseAmount(string $v): float
    {
        $v = preg_replace('/[^0-9,.\-]/', '', $v) ?? '';
        if ($v === '' || str_starts_with($v, '-')) {
            return 0.0;
        }
        $lastComma = strrpos($v, ',');
        $lastDot = strrpos($v, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // koma = desimal bila diikuti 1–2 digit di akhir; selain itu pemisah ribuan
            $v = preg_match('/,\d{1,2}$/', $v) ? str_replace(',', '.', str_replace('.', '', $v)) : str_replace([',', '.'], '', $v);
        } else {
            $v = preg_match('/\.\d{1,2}$/', $v) && substr_count($v, '.') === 1 ? str_replace(',', '', $v) : str_replace([',', '.'], '', $v);
        }

        return round((float) $v, 2);
    }

    private function parseDate(string $v): ?string
    {
        $v = trim($v);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd.m.Y', 'Y/m/d', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'd M Y', 'd/m'] as $format) {
            try {
                $d = Carbon::createFromFormat('!'.$format, $v);
                if ($d && $d->format($format) === $v) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
                // coba format berikutnya
            }
        }

        return null;
    }
}
