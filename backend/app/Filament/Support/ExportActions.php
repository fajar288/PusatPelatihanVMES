<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

/** Tombol ekspor CSV/XLSX ke route admin.export (pola halaman Laporan), membawa filter tabel yang sedang aktif. */
final class ExportActions
{
    /**
     * @param  Closure(): array<string, mixed>  $query
     * @return array<int, ActionGroup>
     */
    public static function make(string $type, Closure $query): array
    {
        $url = fn (string $format) => fn () => route('admin.export', ['type' => $type, 'format' => $format] + $query());

        return [
            ActionGroup::make([
                Action::make('export_csv')->label('CSV')->url($url('csv')),
                Action::make('export_xlsx')->label('Excel (XLSX)')->url($url('xlsx')),
            ])->label('Ekspor')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray'),
        ];
    }

    /**
     * Nilai filter tabel Filament ({nama: {value: x}}) menjadi parameter query ekspor.
     *
     * @param  array<string, mixed>  $tableFilters
     * @param  string[]  $keys
     * @return array<string, string>
     */
    public static function filterQuery(array $tableFilters, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $tableFilters[$key]['value'] ?? null;
            if (filled($value) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
