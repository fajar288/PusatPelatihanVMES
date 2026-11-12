<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Models\OrganizationUnit;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Departemen / cost center / proyek — dipakai untuk batas akses supervisor & sponsor unit enrollment. */
class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    protected static ?string $title = 'Unit (departemen / cost center)';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(255),
            TextInput::make('code')->label('Kode')->maxLength(40)->placeholder('ENG-01'),
            Select::make('type')->label('Jenis')->options(OrganizationUnit::TYPES)->default('department')->required()->native(false),
            Select::make('parent_id')->label('Induk')->nullable()
                ->options(fn (?OrganizationUnit $record) => $this->getOwnerRecord()->units()->when($record, fn ($q) => $q->whereKeyNot($record->id))->orderBy('name')->pluck('name', 'id')->all()),
            Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->description(fn (OrganizationUnit $record) => $record->parent ? 'Induk: '.$record->parent->name : null),
                TextColumn::make('code')->label('Kode')->placeholder('-'),
                TextColumn::make('type')->label('Jenis')->badge()->color('gray')->formatStateUsing(fn ($state) => OrganizationUnit::TYPES[$state] ?? $state),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Tambah unit')])
            ->recordActions([EditAction::make()]);
    }
}
