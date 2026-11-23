<?php

namespace App\Filament\Resources\SiteSettings\Tables;

use App\Models\SiteSetting;
use App\Support\CmsSettings;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SiteSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label('Kunci')
                    ->searchable(),
                TextColumn::make('editor')->label('Disunting di')
                    ->state(fn (SiteSetting $record) => CmsSettings::editorLabel($record->key) ?? 'JSON'),
                TextColumn::make('group')->label('Grup')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('openEditor')
                    ->label('Buka editor')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (SiteSetting $record) => CmsSettings::editorUrl($record->key))
                    ->visible(fn (SiteSetting $record) => CmsSettings::hasEditor($record->key)),
                EditAction::make()
                    ->label(fn (SiteSetting $record) => CmsSettings::hasEditor($record->key) ? 'JSON' : 'Ubah')
                    ->visible(fn (SiteSetting $record) => $record->key !== 'site'),
            ])
            // Pengaturan yang dibaca website (punya editor terstruktur) tidak bisa dihapus massal.
            ->checkIfRecordIsSelectableUsing(fn (SiteSetting $record) => ! CmsSettings::hasEditor($record->key))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
