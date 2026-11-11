<?php

namespace App\Filament\Resources\Registrations;

use App\Enums\RegistrationStatus;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Registrations\Pages\EditRegistration;
use App\Filament\Resources\Registrations\Pages\ListRegistrations;
use App\Models\Registration;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Pendaftaran (PRD §5.5): tinjau data, ubah status lewat aksi "Ubah status" (transisi divalidasi RegistrationService). */
class RegistrationResource extends Resource
{
    protected static ?string $model = Registration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Pendaftaran';

    protected static ?string $modelLabel = 'Pendaftaran';

    protected static ?string $pluralModelLabel = 'Pendaftaran';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pendaftaran')->columns(3)->components([
                TextInput::make('registration_number')->label('Nomor')->disabled()->dehydrated(false),
                TextInput::make('status')->label('Status')->disabled()->dehydrated(false)
                    ->formatStateUsing(fn ($state) => $state instanceof RegistrationStatus ? $state->label() : $state),
                TextInput::make('schedule.code')->label('Kode kelas')->disabled()->dehydrated(false),
                Select::make('type')->label('Jenis')->options(['individual' => 'Individu', 'corporate' => 'Kolektif / perusahaan'])->required()->native(false),
                TextInput::make('participants_count')->label('Jumlah peserta')->numeric()->minValue(1)->required(),
                TextInput::make('total')->label('Total (Rp)')->numeric()->prefix('Rp'),
            ]),
            Section::make('Data pendaftar / PIC')->columns(2)->components([
                TextInput::make('name')->label('Nama')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255),
                TextInput::make('phone')->label('WhatsApp')->required()->maxLength(30),
                TextInput::make('company')->label('Perusahaan / instansi')->maxLength(255),
                TextInput::make('position')->label('Jabatan')->maxLength(255),
                TextInput::make('discount')->label('Diskon (Rp)')->numeric()->default(0),
                Textarea::make('notes')->label('Catatan pendaftar')->rows(3)->columnSpanFull(),
                Textarea::make('status_note')->label('Catatan status (internal/ke pendaftar)')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('registration_number')->label('Nomor')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->description(fn (Registration $r) => $r->company),
                TextColumn::make('schedule.code')->label('Kelas')->searchable(),
                TextColumn::make('type')->label('Jenis')->formatStateUsing(fn ($state) => $state === 'corporate' ? 'Kolektif' : 'Individu'),
                TextColumn::make('participants_count')->label('Peserta')->alignCenter(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RegistrationStatus ? $state->label() : $state)
                    ->color(fn ($state) => match ($state) {
                        RegistrationStatus::Confirmed => 'success',
                        RegistrationStatus::Rejected, RegistrationStatus::Cancelled => 'danger',
                        RegistrationStatus::WaitingPayment, RegistrationStatus::PaymentVerification => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('total')->label('Total')->money('IDR', locale: 'id')->sortable(),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(RegistrationStatus::options()),
                SelectFilter::make('type')->options(['individual' => 'Individu', 'corporate' => 'Kolektif']),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegistrations::route('/'),
            'edit' => EditRegistration::route('/{record}/edit'),
        ];
    }
}
