<x-filament-panels::page>
    @php
        $rupiah = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $isNum = fn ($v) => is_int($v) || is_float($v);
        $fmt = fn ($v) => is_float($v) ? number_format($v, 0, ',', '.') : ($v === null || $v === '' ? '-' : $v);
        $usesRange = ['registrations', 'payments'];
        $descriptions = [
            'registrations' => 'Nomor, status, kontak pendaftar, kelas, paket, harga, diskon & total.',
            'payments' => 'Invoice (sumber, subtotal, diskon, pajak, total, terbayar, refund), termin, jatuh tempo, status & transaksi terakhir.',
            'schedules' => 'Kuota, kursi terisi, sisa kursi, daftar tunggu & trainer per kelas.',
            'enrollments' => 'Peserta per kelas dengan nomor member, status, kehadiran & nilai akhir.',
        ];
    @endphp

    {{-- Gaya ter-scope: CSS panel Filament sudah dikompilasi sehingga utilitas Tailwind kustom di view tidak tersedia. --}}
    <style>
        .ppti-lp { display: grid; gap: 1.5rem; }
        .ppti-lp-range { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem; }
        .ppti-lp-field { display: grid; gap: .375rem; min-width: 12rem; }
        .ppti-lp-field > span { font-size: .875rem; font-weight: 500; color: var(--gray-950); }
        .ppti-lp-hint { margin-top: .75rem; font-size: .8125rem; color: var(--gray-500); }
        .ppti-lp-exports { margin-top: 1.25rem; display: grid; gap: .75rem; }
        @media (min-width: 1024px) { .ppti-lp-exports { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .ppti-lp-export { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1rem; padding: 1rem 1.125rem; border: 1px solid var(--gray-200); border-radius: .75rem; background: var(--gray-50); }
        .ppti-lp-export-body { flex: 1 1 16rem; min-width: 0; }
        .ppti-lp-export-title { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; font-size: .9375rem; font-weight: 600; color: var(--gray-950); }
        .ppti-lp-export-desc { margin-top: .25rem; font-size: .8125rem; line-height: 1.45; color: var(--gray-500); }
        .ppti-lp-export-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .ppti-lp-tablewrap { overflow-x: auto; border: 1px solid var(--gray-200); border-radius: .75rem; }
        .ppti-lp-table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
        .ppti-lp-table th { padding: .625rem .875rem; text-align: left; white-space: nowrap; font-size: .75rem; font-weight: 600; color: var(--gray-600); background: var(--gray-50); border-bottom: 1px solid var(--gray-200); }
        .ppti-lp-table td { padding: .625rem .875rem; white-space: nowrap; color: var(--gray-950); border-bottom: 1px solid var(--gray-100); vertical-align: top; }
        .ppti-lp-table tr:last-child td { border-bottom: 0; }
        .ppti-lp-table tbody tr:hover td { background: var(--gray-50); }
        .ppti-lp-table .num { text-align: right; font-variant-numeric: tabular-nums; }
        .ppti-lp-table td.muted { color: var(--gray-400); }
        .ppti-lp-month { max-width: 28rem; }
        .ppti-lp-empty { font-size: .875rem; color: var(--gray-500); }
        .dark .ppti-lp-field > span, .dark .ppti-lp-export-title, .dark .ppti-lp-table td { color: #fff; }
        .dark .ppti-lp-export { background: rgba(255, 255, 255, .03); border-color: rgba(255, 255, 255, .1); }
        .dark .ppti-lp-tablewrap { border-color: rgba(255, 255, 255, .1); }
        .dark .ppti-lp-table th { background: rgba(255, 255, 255, .05); color: var(--gray-300); border-color: rgba(255, 255, 255, .1); }
        .dark .ppti-lp-table td { border-color: rgba(255, 255, 255, .06); }
        .dark .ppti-lp-table tbody tr:hover td { background: rgba(255, 255, 255, .03); }
        .dark .ppti-lp-export-desc, .dark .ppti-lp-hint, .dark .ppti-lp-empty { color: var(--gray-400); }
    </style>

    <div class="ppti-lp">
        <x-filament::section
            heading="Ekspor laporan"
            description="Unduh data dalam format CSV atau Excel (XLSX)."
            :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray"
        >
            <div class="ppti-lp-range">
                <label class="ppti-lp-field">
                    <span>Dari tanggal</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="from" :max="$to" />
                    </x-filament::input.wrapper>
                </label>
                <label class="ppti-lp-field">
                    <span>Sampai tanggal</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="to" :min="$from" />
                    </x-filament::input.wrapper>
                </label>
                @if ($from || $to)
                    <x-filament::button color="gray" wire:click="resetRange" :icon="\Filament\Support\Icons\Heroicon::OutlinedXMark">
                        Hapus rentang
                    </x-filament::button>
                @endif
            </div>
            <p class="ppti-lp-hint">Rentang tanggal (opsional) hanya berlaku untuk laporan Pendaftaran &amp; Pembayaran, berdasarkan tanggal data dibuat.</p>

            <div class="ppti-lp-exports">
                @foreach ($types as $type => $label)
                    <div class="ppti-lp-export">
                        <div class="ppti-lp-export-body">
                            <div class="ppti-lp-export-title">
                                {{ $label }}
                                @if (in_array($type, $usesRange, true) && ($from || $to))
                                    <x-filament::badge size="sm" color="info">Sesuai rentang</x-filament::badge>
                                @endif
                            </div>
                            <div class="ppti-lp-export-desc">{{ $descriptions[$type] ?? '' }}</div>
                        </div>
                        <div class="ppti-lp-export-actions">
                            <x-filament::button tag="a" size="sm" color="gray" :href="$exportUrl($type, 'csv')" :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentText">
                                CSV
                            </x-filament::button>
                            <x-filament::button tag="a" size="sm" :href="$exportUrl($type, 'xlsx')" :icon="\Filament\Support\Icons\Heroicon::OutlinedTableCells">
                                Excel
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section
            heading="Pendapatan terverifikasi per bulan"
            description="Enam bulan terakhir yang memiliki pembayaran terverifikasi."
            :icon="\Filament\Support\Icons\Heroicon::OutlinedBanknotes"
        >
            @if (empty($monthly))
                <p class="ppti-lp-empty">Belum ada pembayaran terverifikasi.</p>
            @else
                <div class="ppti-lp-tablewrap ppti-lp-month">
                    <table class="ppti-lp-table">
                        <thead>
                            <tr>
                                <th>Bulan</th>
                                <th class="num">Pendapatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($monthly as $month => $amount)
                                <tr>
                                    <td>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->locale('id')->translatedFormat('F Y') }}</td>
                                    <td class="num">{{ $rupiah($amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @foreach ($preview as $type => $rows)
            <x-filament::section
                :heading="'Pratinjau: ' . $types[$type]"
                description="Maksimal 8 baris pertama. Unduh ekspor untuk data lengkap."
                collapsible
                collapsed
            >
                @if ($rows->isEmpty())
                    <p class="ppti-lp-empty">Belum ada data.</p>
                @else
                    @php $numeric = collect(array_keys($rows->first()))->mapWithKeys(fn ($h) => [$h => $rows->contains(fn ($r) => $isNum($r[$h]))]); @endphp
                    <div class="ppti-lp-tablewrap">
                        <table class="ppti-lp-table">
                            <thead>
                                <tr>
                                    @foreach (array_keys($rows->first()) as $h)
                                        <th @class(['num' => $numeric[$h]])>{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        @foreach ($row as $h => $v)
                                            <td @class(['num' => $numeric[$h], 'muted' => $v === null || $v === ''])>{{ $fmt($v) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
