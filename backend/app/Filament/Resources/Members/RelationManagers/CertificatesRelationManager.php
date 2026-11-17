<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Certificate;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Sertifikat peserta (baca saja), tautan ke resource Sertifikat. */
class CertificatesRelationManager extends RelationManager
{
    protected static string $relationship = 'certificates';

    protected static ?string $title = 'Sertifikat';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'issued' => 'success', 'revoked' => 'danger', 'replaced' => 'gray', default => 'warning',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nomor')->placeholder('(draf)')->searchable(),
                TextColumn::make('program_title')->label('Program')->wrap(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state) => Certificate::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => self::statusColor($state)),
                TextColumn::make('issued_at')->label('Tanggal')->date('d M Y')->placeholder('-'),
                TextColumn::make('valid_until')->label('Berlaku hingga')->date('d M Y')->placeholder('Tanpa batas'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('open')->label('Buka')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn () => CertificateResource::canViewAny())
                    ->url(fn (Certificate $record) => CertificateResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
