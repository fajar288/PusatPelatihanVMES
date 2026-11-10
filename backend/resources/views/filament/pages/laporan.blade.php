<x-filament-panels::page>
    @php
        $rupiah = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    @endphp

    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section heading="Pendaftaran">
            <div class="text-3xl font-bold">{{ $overview['registrations_total'] }}</div>
            <ul class="mt-2 text-sm text-gray-500">
                @foreach ($overview['registrations_by_status'] as $st)
                    @if ($st['count'] > 0)
                        <li>{{ $st['label'] }}: <b>{{ $st['count'] }}</b></li>
                    @endif
                @endforeach
            </ul>
        </x-filament::section>
        <x-filament::section heading="Kelas mendatang">
            <div class="text-3xl font-bold">{{ $overview['upcoming_classes'] }}</div>
            <p class="mt-2 text-sm text-gray-500">Peserta aktif: <b>{{ $overview['enrollments_active'] }}</b></p>
        </x-filament::section>
        <x-filament::section heading="Pendapatan terverifikasi">
            <div class="text-3xl font-bold">{{ $rupiah($overview['revenue']['verified']) }}</div>
            <p class="mt-2 text-sm text-gray-500">Menunggu verifikasi: <b>{{ $rupiah($overview['revenue']['pending']) }}</b></p>
        </x-filament::section>
        <x-filament::section heading="Piutang (belum dibayar)">
            <div class="text-3xl font-bold">{{ $rupiah($overview['revenue']['outstanding']) }}</div>
            <ul class="mt-2 text-sm text-gray-500">
                @foreach (array_slice($overview['revenue']['by_month'], -6, null, true) as $m => $v)
                    <li>{{ $m }}: <b>{{ $rupiah($v) }}</b></li>
                @endforeach
            </ul>
        </x-filament::section>
    </div>

    <x-filament::section heading="Ekspor" description="Rentang tanggal berlaku untuk laporan pendaftaran & pembayaran (berdasarkan tanggal dibuat).">
        <form method="get" class="flex flex-wrap items-end gap-3" wire:submit.prevent>
            <label class="text-sm">Dari <input type="date" wire:model.live="from" class="rounded-lg border-gray-300 text-sm"></label>
            <label class="text-sm">Sampai <input type="date" wire:model.live="to" class="rounded-lg border-gray-300 text-sm"></label>
        </form>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
            @foreach ($types as $type => $label)
                <div class="flex items-center justify-between rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <span class="font-medium">{{ $label }}</span>
                    <span class="flex gap-2">
                        <x-filament::button tag="a" size="sm" color="gray" :href="$exportUrl($type, 'csv')">CSV</x-filament::button>
                        <x-filament::button tag="a" size="sm" :href="$exportUrl($type, 'xlsx')">XLSX</x-filament::button>
                    </span>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    @foreach ($preview as $type => $rows)
        <x-filament::section :heading="$types[$type] . ' (8 baris pertama)'" collapsible collapsed>
            @if ($rows->isEmpty())
                <p class="text-sm text-gray-500">Belum ada data.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr>
                                @foreach (array_keys($rows->first()) as $h)
                                    <th class="whitespace-nowrap px-2 py-1 text-left font-semibold">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    @foreach ($row as $v)
                                        <td class="whitespace-nowrap px-2 py-1">{{ is_float($v) ? number_format($v, 0, ',', '.') : $v }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
