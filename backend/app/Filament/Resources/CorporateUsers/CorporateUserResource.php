<?php

namespace App\Filament\Resources\CorporateUsers;

use App\Filament\NavigationGroup;
use App\Filament\Resources\CorporateUsers\Pages\ManageCorporateUsers;
use App\Filament\Support\EnterpriseFields;
use App\Models\CorporateUser;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Pengguna perusahaan (Roadmap 5 §20.12): owner, HR/L&D, supervisor, finance, viewer + batas unit opsional.
 * Semua perubahan akses lewat OrganizationService (audit R10). Akses dicabut berlaku seketika.
 */
class CorporateUserResource extends Resource
{
    protected static ?string $model = CorporateUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Corporate;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Pengguna perusahaan';

    protected static ?string $modelLabel = 'Pengguna perusahaan';

    protected static ?string $pluralModelLabel = 'Pengguna perusahaan';

    public static function canViewAny(): bool
    {
        return EnterpriseFields::canManage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            EnterpriseFields::organization(),
            EnterpriseFields::user(),
            Select::make('role')->label('Peran perusahaan')->options(CorporateUser::ROLES)->default('hr_admin')->required()->native(false),
            EnterpriseFields::unit()->helperText('Kosong = seluruh organisasi. Diisi = hanya unit ini dan turunannya (mis. supervisor).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Nama')->searchable()->description(fn (CorporateUser $record) => $record->user?->email),
                TextColumn::make('organization.legal_name')->label('Organisasi')->searchable(),
                TextColumn::make('role')->label('Peran')->badge()->color('info')->formatStateUsing(fn ($state) => CorporateUser::ROLES[$state] ?? $state),
                TextColumn::make('unit.name')->label('Batas unit')->placeholder('Seluruh organisasi'),
                TextColumn::make('status')->label('Status')->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => $state === 'active' ? 'Aktif' : 'Dicabut')->description(fn (CorporateUser $record) => $record->disabled_reason),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Aktif', 'disabled' => 'Dicabut']),
                SelectFilter::make('role')->label('Peran')->options(CorporateUser::ROLES),
                SelectFilter::make('organization_id')->label('Organisasi')->relationship('organization', 'legal_name')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('role')->label('Ubah peran')->icon('heroicon-o-key')->color('gray')
                    ->visible(fn (CorporateUser $record) => $record->isActive())
                    ->fillForm(fn (CorporateUser $record) => ['role' => $record->role, 'unit_id' => $record->unit_id, 'organization_id' => $record->organization_id])
                    ->schema([
                        Select::make('role')->label('Peran')->options(CorporateUser::ROLES)->required()->native(false),
                        EnterpriseFields::unit(),
                    ])
                    ->action(fn (array $data, CorporateUser $record, Action $action) => EnterpriseFields::attempt($action, fn () => app(OrganizationService::class)->changeRole($record, $data['role'], OrganizationUnit::query()->find($data['unit_id'] ?? null), auth()->user()), 'Peran diperbarui')),
                Action::make('disable')->label('Cabut akses')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (CorporateUser $record) => $record->isActive())
                    ->schema([TextInput::make('reason')->label('Alasan')->required()->maxLength(255)])
                    ->action(function (array $data, CorporateUser $record): void {
                        app(OrganizationService::class)->disableCorporateUser($record, $data['reason'], auth()->user());
                        Notification::make()->title('Akses dicabut')->success()->send();
                    }),
                Action::make('enable')->label('Aktifkan lagi')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (CorporateUser $record) => ! $record->isActive())->requiresConfirmation()
                    ->action(fn (CorporateUser $record) => app(OrganizationService::class)->addCorporateUser($record->organization, $record->user, $record->role, $record->unit, auth()->user())),
            ]);
    }

    public static function createViaService(array $data): CorporateUser
    {
        return app(OrganizationService::class)->addCorporateUser(
            Organization::query()->findOrFail($data['organization_id']),
            User::query()->findOrFail($data['user_id']),
            $data['role'],
            OrganizationUnit::query()->find($data['unit_id'] ?? null),
            auth()->user(),
        );
    }

    public static function getPages(): array
    {
        return ['index' => ManageCorporateUsers::route('/')];
    }
}
