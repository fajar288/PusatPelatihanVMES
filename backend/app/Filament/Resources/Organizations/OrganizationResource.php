<?php

namespace App\Filament\Resources\Organizations;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\RelationManagers\SponsoredEnrollmentsRelationManager;
use App\Filament\Resources\Organizations\RelationManagers\UnitsRelationManager;
use App\Filament\Support\EnterpriseFields;
use App\Filament\Support\Fields;
use App\Models\Organization;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Akun perusahaan (Roadmap 5 §4.1): profil legal, kontak, data pajak/invoice, status, kebijakan akses setelah resign. */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Corporate;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Organisasi';

    protected static ?string $modelLabel = 'Organisasi';

    protected static ?string $pluralModelLabel = 'Organisasi';

    protected static ?string $recordTitleAttribute = 'legal_name';

    public static function canViewAny(): bool
    {
        return EnterpriseFields::canManage();
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Organization::query()->where('needs_review', true)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Organisasi otomatis yang perlu diperiksa';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profil perusahaan')->columns(2)->components([
                TextInput::make('legal_name')->label('Nama legal')->required()->maxLength(255)->placeholder('PT ABC Manufaktur'),
                TextInput::make('display_name')->label('Nama tampilan')->maxLength(255),
                TextInput::make('industry')->label('Industri')->maxLength(100),
                TextInput::make('city')->label('Kota')->maxLength(80),
                Textarea::make('address')->label('Alamat')->rows(2)->columnSpanFull(),
                TextInput::make('phone')->label('Telepon')->tel()->maxLength(30),
                TextInput::make('email')->label('Email')->email(),
                TextInput::make('website')->label('Website')->url(),
                Fields::image('logo', 'organizations', 600)->label('Logo'),
            ]),
            Section::make('Kontak PIC')->columns(3)->components([
                TextInput::make('contact_name')->label('Nama'),
                TextInput::make('contact_email')->label('Email')->email(),
                TextInput::make('contact_phone')->label('HP')->tel()->maxLength(30),
            ]),
            Section::make('Pajak & invoice')->columns(2)->components([
                TextInput::make('tax_id')->label('NPWP')->maxLength(40),
                TextInput::make('invoice_name')->label('Nama di invoice'),
                TextInput::make('invoice_email')->label('Email penagihan')->email(),
                Textarea::make('invoice_address')->label('Alamat penagihan')->rows(2),
            ]),
            Section::make('Status & kebijakan')->columns(2)->components([
                Select::make('status')->label('Status')->options(Organization::STATUSES)->default('active')->required()->native(false)
                    ->helperText('Tidak aktif/ditangguhkan: seluruh pengguna perusahaan kehilangan akses. Histori enrollment tetap.'),
                Select::make('settings.exit_access')->label('Akses setelah karyawan keluar')->options(Organization::EXIT_ACCESS)->default('summary')->native(false)
                    ->helperText('Berlaku untuk enrollment bersponsor milik peserta yang employment-nya sudah tidak aktif.'),
                Toggle::make('settings.requires_approval')->label('Wajib persetujuan internal perusahaan')->inline(false)
                    ->helperText('Penawaran yang diterima HR menunggu persetujuan Corporate Owner sebelum disetujui.'),
                Toggle::make('needs_review')->label('Perlu diperiksa')->helperText('Dibuat otomatis dari pendaftaran/backfill. Matikan setelah data diverifikasi.')->inline(false),
                Textarea::make('notes')->label('Catatan internal VMES')->rows(2)->helperText('Tidak pernah ditampilkan ke perusahaan.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('legal_name')
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('legal_name')->label('Nama')->searchable(['legal_name', 'display_name'])->sortable()->description(fn (Organization $record) => $record->industry),
                TextColumn::make('city')->label('Kota')->toggleable(),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => Organization::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
                IconColumn::make('needs_review')->label('Periksa')->boolean()->trueColor('warning')->falseIcon(''),
                TextColumn::make('corporate_users_count')->label('Pengguna')->counts(['corporateUsers' => fn ($q) => $q->where('status', 'active')]),
                TextColumn::make('employments_count')->label('Karyawan aktif')->counts(['employments' => fn ($q) => $q->where('status', 'active')]),
                TextColumn::make('sponsored_enrollments_count')->label('Enrollment bersponsor')->counts('sponsoredEnrollments'),
                TextColumn::make('source')->label('Sumber')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(Organization::STATUSES),
                TernaryFilter::make('needs_review')->label('Perlu diperiksa'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [UnitsRelationManager::class, SponsoredEnrollmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'create' => CreateOrganization::route('/create'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
    }
}
