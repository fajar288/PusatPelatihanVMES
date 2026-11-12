<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\NavigationGroup;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplates\RelationManagers\VersionsRelationManager;
use App\Models\NotificationTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Template pesan notifikasi berversi (PRD §5.12, Roadmap 5 §6.1): satu template per peristiwa × audiens
 * (peserta, admin perusahaan, trainer, admin VMES); judul/isi aplikasi & push, email, WhatsApp, kanal, aktif.
 * Setiap simpan yang mengubah isi menaikkan versi; riwayat & pemulihan di tab "Riwayat versi".
 */
class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Notifications;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Template notifikasi';

    protected static ?string $modelLabel = 'Template notifikasi';

    protected static ?string $pluralModelLabel = 'Template notifikasi';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('notifications.manage') || $u?->isSuperAdmin());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Peristiwa')->columns(3)->components([
                TextEntry::make('key')->label('Kunci')->fontFamily('mono'),
                TextEntry::make('audience')->label('Audiens')->badge()->formatStateUsing(fn ($state) => NotificationTemplate::AUDIENCES[$state] ?? $state),
                TextEntry::make('version')->label('Versi')->formatStateUsing(fn ($state, ?NotificationTemplate $record) => 'v'.$state.($record?->editor ? ' - diubah '.$record->editor->name : '')),
                TextEntry::make('category')->label('Kategori')->formatStateUsing(fn ($state) => NotificationTemplate::CATEGORIES[$state][0] ?? $state),
                TextEntry::make('placeholders')->label('Placeholder tersedia')
                    ->state(fn (?NotificationTemplate $record) => collect($record?->placeholders ?? [])->map(fn ($p) => '{'.$p.'}')->implode(' ')),
            ]),
            Section::make('Notifikasi aplikasi & push')->components([
                TextInput::make('name')->label('Nama template')->required()->maxLength(255),
                TextInput::make('title')->label('Judul')->required()->maxLength(255),
                Textarea::make('body')->label('Isi singkat')->required()->rows(3)->maxLength(1000),
            ]),
            Section::make('Email')->description('Dipakai bila kanal Email aktif. Isi kosong = memakai isi singkat. Paragraf dipisah baris kosong; **tebal** didukung.')
                ->components([
                    TextInput::make('mail_subject')->label('Subjek email')->maxLength(255),
                    Textarea::make('mail_body')->label('Isi email')->rows(6),
                ]),
            Section::make('WhatsApp')->description('Dipakai bila kanal WhatsApp aktif dan WHATSAPP_ENABLED=true (saat ini driver log, belum ada penyedia). Kosong = judul + isi singkat + tautan. *tebal* gaya WhatsApp didukung.')
                ->components([
                    Textarea::make('wa_body')->label('Isi WhatsApp')->rows(4)->maxLength(1500),
                ]),
            Section::make('Pengiriman')->columns(2)->components([
                CheckboxList::make('channels')->label('Kanal')->options(NotificationTemplate::CHANNELS)->required()
                    ->helperText('Email, push & WhatsApp mengikuti preferensi tiap penerima. Email transaksional peserta (pendaftaran, sertifikat, perubahan jadwal) selalu dikirim terpisah. Audiens admin hanya memakai Aplikasi (lonceng panel admin).'),
                Toggle::make('is_active')->label('Aktif')->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')->label('Template')->searchable()->description(fn (NotificationTemplate $record) => $record->key),
                TextColumn::make('audience')->label('Audiens')->badge()->color('gray')->formatStateUsing(fn ($state) => NotificationTemplate::AUDIENCES[$state] ?? $state),
                TextColumn::make('category')->label('Kategori')->badge()->formatStateUsing(fn ($state) => NotificationTemplate::CATEGORIES[$state][0] ?? $state),
                TextColumn::make('title')->label('Judul')->limit(50),
                TextColumn::make('channels')->label('Kanal')->badge()->formatStateUsing(fn ($state) => NotificationTemplate::CHANNELS[$state] ?? $state),
                TextColumn::make('version')->label('Versi')->formatStateUsing(fn ($state) => 'v'.$state),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                SelectFilter::make('audience')->label('Audiens')->options(NotificationTemplate::AUDIENCES),
                SelectFilter::make('category')->label('Kategori')->options(collect(NotificationTemplate::CATEGORIES)->map(fn ($c) => $c[0])->all()),
            ])
            ->paginated(false)
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
