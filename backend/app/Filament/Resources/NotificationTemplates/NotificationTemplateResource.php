<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
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

/** Template pesan notifikasi (PRD §5.12): judul/isi aplikasi & push, subjek/isi email, kanal, aktif. */
class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Notifikasi';

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
            Section::make('Pengiriman')->columns(2)->components([
                CheckboxList::make('channels')->label('Kanal')->options(NotificationTemplate::CHANNELS)->required()
                    ->helperText('Email & push mengikuti preferensi tiap pengguna. Email transaksional (pendaftaran, sertifikat, perubahan jadwal) selalu dikirim terpisah.'),
                Toggle::make('is_active')->label('Aktif')->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('category')
            ->columns([
                TextColumn::make('name')->label('Template')->searchable()->description(fn (NotificationTemplate $record) => $record->key),
                TextColumn::make('category')->label('Kategori')->badge()->formatStateUsing(fn ($state) => NotificationTemplate::CATEGORIES[$state][0] ?? $state),
                TextColumn::make('title')->label('Judul')->limit(50),
                TextColumn::make('channels')->label('Kanal')->badge()->formatStateUsing(fn ($state) => NotificationTemplate::CHANNELS[$state] ?? $state),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([SelectFilter::make('category')->label('Kategori')->options(collect(NotificationTemplate::CATEGORIES)->map(fn ($c) => $c[0])->all())])
            ->paginated(false)
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
