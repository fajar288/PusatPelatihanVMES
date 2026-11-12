<?php

namespace App\Filament\Resources\NotificationTemplates\RelationManagers;

use App\Models\AuditLog;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateVersion;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Riwayat versi template (tidak bisa diubah). "Pulihkan" menyalin isi versi lama menjadi versi baru. */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Riwayat versi';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->label('Versi')->formatStateUsing(fn ($state) => 'v'.$state),
                TextColumn::make('title')->label('Judul')->limit(60)->description(fn (NotificationTemplateVersion $record) => str($record->body)->limit(90)),
                TextColumn::make('channels')->label('Kanal')->badge()->color('gray')->formatStateUsing(fn ($state) => NotificationTemplate::CHANNELS[$state] ?? $state),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('author.name')->label('Oleh')->placeholder('Sistem'),
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i'),
            ])
            ->recordActions([
                Action::make('view')->label('Lihat')->icon('heroicon-o-eye')->color('gray')->modalSubmitAction(false)
                    ->modalHeading(fn (NotificationTemplateVersion $record) => 'Versi v'.$record->version)
                    ->schema([
                        TextEntry::make('title')->label('Judul'),
                        TextEntry::make('body')->label('Isi singkat'),
                        TextEntry::make('mail_subject')->label('Subjek email')->placeholder('-'),
                        TextEntry::make('mail_body')->label('Isi email')->placeholder('-'),
                        TextEntry::make('wa_body')->label('Isi WhatsApp')->placeholder('-'),
                    ]),
                Action::make('restore')->label('Pulihkan')->icon('heroicon-o-arrow-uturn-left')->requiresConfirmation()
                    ->modalDescription('Isi versi ini disalin menjadi versi terbaru. Riwayat lama tetap tersimpan.')
                    ->visible(fn (NotificationTemplateVersion $record) => $record->version !== $this->getOwnerRecord()->version)
                    ->action(function (NotificationTemplateVersion $record): void {
                        /** @var NotificationTemplate $tpl */
                        $tpl = $this->getOwnerRecord();
                        $before = $tpl->version;
                        $tpl->restoreVersion($record);
                        AuditLog::record('notification_template.restored', $tpl, ['version' => $before], ['version' => $tpl->version, 'from_version' => $record->version]);
                        Notification::make()->title("Versi v{$record->version} dipulihkan sebagai v{$tpl->version}")->success()->send();
                    }),
            ]);
    }
}
