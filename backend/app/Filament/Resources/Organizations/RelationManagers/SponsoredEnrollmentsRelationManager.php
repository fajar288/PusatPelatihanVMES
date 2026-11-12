<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Models\Enrollment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Enrollment yang disponsori organisasi ini (R3). Sponsor hanya bisa diubah lewat menu Transfer sponsor (R7/R8). */
class SponsoredEnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'sponsoredEnrollments';

    protected static ?string $title = 'Enrollment bersponsor';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('participant_name')->label('Peserta')->searchable()
                    ->description(fn (Enrollment $record) => $record->member?->member_number ?? 'Belum bertaut member'),
                TextColumn::make('schedule.code')->label('Kelas')->description(fn (Enrollment $record) => $record->schedule?->package?->title),
                TextColumn::make('sponsorUnit.name')->label('Unit')->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('enrolled_at')->label('Terdaftar')->date('d M Y'),
            ])
            ->filters([SelectFilter::make('status')->options(array_combine(Enrollment::STATUSES, Enrollment::STATUSES))]);
    }
}
