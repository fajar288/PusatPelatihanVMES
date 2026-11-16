<?php

namespace App\Filament\Resources\Competencies\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Program yang membangun kompetensi ini (package_competencies). Level = capaian lulusan program (0-100): dipakai sebagai
 * bukti "training" bila belum ada penilaian rubrik, dan untuk memilih rekomendasi program yang menutup gap.
 */
class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = 'Program yang membangun kompetensi';

    public function isReadOnly(): bool
    {
        return false;
    }

    private static function levelField(): TextInput
    {
        return TextInput::make('level')->label('Level capaian lulusan (0-100)')->numeric()->minValue(0)->maxValue(100)->default(70)->required();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([self::levelField()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')->label('Program')->searchable(),
                TextColumn::make('level')->label('Level')->suffix(' / 100')->sortable(),
                TextColumn::make('status')->label('Status')->badge()->color(fn ($state) => $state === 'published' ? 'success' : 'gray'),
            ])
            ->headerActions([
                AttachAction::make()->label('Tautkan program')->preloadRecordSelect()
                    ->schema(fn (AttachAction $action) => [$action->getRecordSelect(), self::levelField()]),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah level'),
                DetachAction::make()->label('Lepas'),
            ]);
    }
}
