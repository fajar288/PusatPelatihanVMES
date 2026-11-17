<?php

namespace App\Filament\Resources\Users;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Sistem - Pengguna: akun login (admin, trainer, peserta, perusahaan). Ubah nama/peran, nonaktifkan/aktifkan,
 * kirim ulang verifikasi, kirim tautan reset sandi. Tanpa hapus permanen dan tanpa akses ke sandi.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Pengguna';

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?string $slug = 'pengguna';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->isSuperAdmin() || $u?->hasPermission('users.manage'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return array<string, string> slug => nama peran; super admin hanya ditawarkan ke super admin */
    public static function roleOptions(): array
    {
        $names = Role::query()->pluck('name', 'slug')->all();
        $options = [];
        foreach (User::ROLES as $slug) {
            if ($slug === User::ROLE_SUPER_ADMIN && ! auth()->user()?->isSuperAdmin()) {
                continue;
            }
            $options[$slug] = $names[$slug] ?? $slug;
        }

        return $options;
    }

    public static function roleLabel(?string $slug): string
    {
        static $names;
        $names ??= Role::query()->pluck('name', 'slug')->all();

        return $names[$slug] ?? (string) $slug;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->copyable(),
                TextColumn::make('role')->label('Peran')->badge()
                    ->state(fn (User $record) => array_map(fn ($slug) => self::roleLabel($slug), $record->roleSlugs()))
                    ->color(fn (string $state) => in_array($state, [self::roleLabel(User::ROLE_SUPER_ADMIN), self::roleLabel(User::ROLE_ADMINISTRATOR)], true) ? 'warning' : 'gray'),
                IconColumn::make('email_verified_at')->label('Email terverifikasi')->boolean()->alignCenter()
                    ->state(fn (User $record) => $record->email_verified_at !== null),
                TextColumn::make('deactivated_at')->label('Status')->badge()
                    ->state(fn (User $record) => $record->isActive() ? 'Aktif' : 'Nonaktif')
                    ->color(fn (string $state) => $state === 'Aktif' ? 'success' : 'danger'),
                TextColumn::make('last_login_at')->label('Terakhir login')->dateTime('d M Y H:i')->placeholder('-')->sortable(),
                TextColumn::make('created_at')->label('Dibuat')->date('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Peran')->options(fn () => Role::query()->pluck('name', 'slug')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $role) => $q->where(fn ($w) => $w->where('role', $role)->orWhereHas('roles', fn ($r) => $r->where('slug', $role))))),
                SelectFilter::make('status')->label('Status akun')->options(['active' => 'Aktif', 'inactive' => 'Nonaktif'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('deactivated_at'),
                        'inactive' => $query->whereNotNull('deactivated_at'),
                        default => $query,
                    }),
                TernaryFilter::make('email_verified_at')->label('Email terverifikasi')->nullable(),
            ])
            ->recordActions([
                Action::make('edit')->label('Ubah')->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(fn (User $record) => 'Ubah akun '.$record->email)
                    ->fillForm(fn (User $record) => [
                        'name' => $record->name,
                        'role' => $record->role,
                        'extra_roles' => array_values(array_diff($record->roleSlugs(), [$record->role])),
                    ])
                    ->schema(fn (User $record) => [
                        TextInput::make('name')->label('Nama')->required()->maxLength(255),
                        Select::make('role')->label('Peran utama')->required()->native(false)
                            ->options(fn () => self::roleOptions() + [$record->role => self::roleLabel($record->role)])
                            ->disabled(fn () => auth()->user()?->is($record))
                            ->helperText(fn () => auth()->user()?->is($record) ? 'Peran akun sendiri tidak bisa diubah.' : 'Peran peserta (Student/Alumni) disesuaikan otomatis dari status member.'),
                        Select::make('extra_roles')->label('Peran tambahan')->multiple()->native(false)
                            ->options(fn () => self::roleOptions() + collect($record->roleSlugs())->mapWithKeys(fn ($s) => [$s => self::roleLabel($s)])->all())
                            ->disabled(fn () => auth()->user()?->is($record)),
                    ])
                    ->action(function (User $record, array $data): void {
                        self::run(fn (UserAccountService $svc) => $svc->update(
                            auth()->user(), $record, $data['name'],
                            $data['role'] ?? $record->role,
                            $data['extra_roles'] ?? array_values(array_diff($record->roleSlugs(), [$record->role])),
                        ), 'Akun diperbarui.');
                    }),
                ActionGroup::make([
                    Action::make('deactivate')->label('Nonaktifkan')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->visible(fn (User $record) => $record->isActive() && ! auth()->user()?->is($record))
                        ->requiresConfirmation()
                        ->modalDescription('Akun tidak bisa login dan semua sesi serta token API-nya dicabut. Data akun tetap tersimpan dan bisa diaktifkan lagi.')
                        ->schema([Textarea::make('reason')->label('Alasan (opsional)')->rows(2)->maxLength(255)])
                        ->action(fn (User $record, array $data) => self::run(fn (UserAccountService $svc) => $svc->deactivate(auth()->user(), $record, $data['reason'] ?? null), 'Akun dinonaktifkan.')),
                    Action::make('activate')->label('Aktifkan')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                        ->visible(fn (User $record) => ! $record->isActive())
                        ->requiresConfirmation()
                        ->action(fn (User $record) => self::run(fn (UserAccountService $svc) => $svc->activate(auth()->user(), $record), 'Akun diaktifkan.')),
                    Action::make('resendVerification')->label('Kirim ulang verifikasi email')->icon(Heroicon::OutlinedEnvelope)
                        ->visible(fn (User $record) => $record->isActive() && $record->email_verified_at === null)
                        ->requiresConfirmation()
                        ->action(fn (User $record) => self::run(fn (UserAccountService $svc) => $svc->resendVerification(auth()->user(), $record), 'Email verifikasi dikirim.')),
                    Action::make('sendPasswordReset')->label('Kirim tautan reset sandi')->icon(Heroicon::OutlinedKey)
                        ->visible(fn (User $record) => $record->isActive())
                        ->requiresConfirmation()
                        ->modalDescription('Tautan reset dikirim ke email pengguna. Admin tidak melihat tautan atau sandi.')
                        ->action(fn (User $record) => self::run(fn (UserAccountService $svc) => $svc->sendPasswordReset(auth()->user(), $record), 'Tautan reset sandi dikirim.')),
                ]),
            ]);
    }

    /** Jalankan aksi layanan; pelanggaran pengaman tampil sebagai notifikasi, bukan error. */
    private static function run(callable $callback, string $success): void
    {
        try {
            $callback(app(UserAccountService::class));
            Notification::make()->title($success)->success()->send();
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }
}
