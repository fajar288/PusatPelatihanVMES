<?php

namespace App\Filament\Resources\Certificates\RelationManagers;

use App\Models\AuditLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Riwayat penerbitan (audit log sertifikat) — hanya baca. */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'history';

    protected static ?string $title = 'Riwayat penerbitan';

    private const ACTIONS = [
        'certificate.created' => 'Draf dibuat', 'certificate.updated' => 'Data diubah', 'certificate.submitted' => 'Diajukan validasi', 'certificate.rejected' => 'Dikembalikan ke draf',
        'certificate.issued' => 'Diterbitkan', 'certificate.regenerated' => 'PDF dibuat ulang', 'certificate.revoked' => 'Dicabut', 'certificate.replaced' => 'Diganti', 'certificate.downloaded' => 'Diunduh peserta',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i'),
                TextColumn::make('action')->label('Aksi')->badge()->formatStateUsing(fn ($state) => self::ACTIONS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'certificate.issued' => 'success', 'certificate.revoked' => 'danger', 'certificate.replaced', 'certificate.rejected' => 'warning', default => 'gray'
                    }),
                TextColumn::make('actor_name')->label('Oleh'),
                TextColumn::make('after')->label('Keterangan')->state(fn (AuditLog $l) => collect($l->after ?? [])->except(['pdf'])->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode('; ', array_map(fn ($x) => is_array($x) ? json_encode($x) : (string) $x, $v)) : (is_bool($v) ? ($v ? 'ya' : 'tidak') : (string) $v)))->implode(' • '))->wrap(),
            ])
            ->paginated([10, 25])
            ->headerActions([])
            ->recordActions([]);
    }
}
