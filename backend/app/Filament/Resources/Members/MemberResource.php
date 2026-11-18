<?php

namespace App\Filament\Resources\Members;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\CertificatesRelationManager;
use App\Filament\Resources\Members\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\Members\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Members\RelationManagers\RegistrationsRelationManager;
use App\Filament\Support\Layout;
use App\Models\Member;
use App\Models\TrainingPackage;
use App\Services\MemberService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Peserta (member): profil, riwayat pendaftaran, enrollment & progres, sertifikat, invoice.
 * Status member dihitung otomatis oleh MemberService (tidak bisa diubah manual). Data pribadi hanya untuk izin members.view.
 */
class MemberResource extends Resource
{
    public const STATUS_LABELS = ['registered' => 'Terdaftar', 'active' => 'Aktif', 'alumni' => 'Alumni', 'inactive' => 'Tidak aktif'];

    /** Field profil yang boleh diubah admin. */
    public const SAFE_FIELDS = ['phone', 'company', 'position', 'education', 'city', 'address'];

    public const STATUS_COLORS = ['registered' => 'gray', 'active' => 'info', 'alumni' => 'success', 'inactive' => 'warning'];

    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Peserta';

    protected static ?string $modelLabel = 'Peserta';

    protected static ?string $pluralModelLabel = 'Peserta';

    protected static ?string $slug = 'peserta';

    protected static ?string $recordRouteKeyName = 'member_number';

    public static function canAccessMembers(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->isSuperAdmin() || $u?->hasPermission('members.view'));
    }

    public static function canViewAny(): bool
    {
        return self::canAccessMembers();
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user')
            ->withCount(['registrations', 'enrollments as completed_count' => fn (Builder $q) => $q->where('status', 'completed')]);
    }

    /** Field profil yang aman diubah admin: kontak & pekerjaan. Nomor member, email login, NIK, dan status tidak diubah di sini. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profil peserta')->description('Nomor member, email login, NIK, dan status member tidak bisa diubah di sini. Status dihitung otomatis dari enrollment.')
                ->columns(2)->components([
                    TextInput::make('phone')->label('Telepon / WhatsApp')->tel()->maxLength(30),
                    TextInput::make('company')->label('Perusahaan / instansi')->maxLength(255),
                    TextInput::make('position')->label('Jabatan')->maxLength(255),
                    TextInput::make('education')->label('Pendidikan terakhir')->maxLength(60),
                    TextInput::make('city')->label('Kota')->maxLength(80),
                    Textarea::make('address')->label('Alamat')->rows(2)->columnSpanFull(),
                    // Foto profil = data pribadi: disk privat, dibuka lewat URL sementara bertanda tangan.
                    FileUpload::make('photo')->label('Foto profil')->image()->avatar()
                        ->disk(MemberService::PHOTO_DISK)->visibility('private')->directory('members/photos')
                        ->imageResizeMode('cover')->imageCropAspectRatio('1:1')
                        ->imageResizeTargetWidth((string) MemberService::PHOTO_SIZE)->imageResizeTargetHeight((string) MemberService::PHOTO_SIZE)
                        ->maxSize(4096)
                        ->helperText('Biasanya diunggah peserta sendiri dari portal. Hapus bila foto tidak pantas.'),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profil')->columns(Layout::cols(3))->components([
                ImageEntry::make('photo')->label('Foto')->circular()->imageSize(72)
                    ->disk(MemberService::PHOTO_DISK)->visibility('private')
                    ->placeholder('Belum ada foto')->columnSpanFull(),
                TextEntry::make('member_number')->label('Nomor member')->copyable(),
                TextEntry::make('user.name')->label('Nama'),
                TextEntry::make('user.email')->label('Email')->copyable(),
                TextEntry::make('phone')->label('Telepon')->placeholder('-'),
                TextEntry::make('company')->label('Perusahaan / instansi')->placeholder('-'),
                TextEntry::make('position')->label('Jabatan')->placeholder('-'),
                TextEntry::make('status')->label('Status member')->badge()
                    ->formatStateUsing(fn (?string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (?string $state) => self::STATUS_COLORS[$state] ?? 'gray')
                    ->helperText('Dihitung otomatis dari enrollment.'),
                TextEntry::make('alumni_since')->label('Alumni sejak')->date('d M Y')->placeholder('-'),
                IconEntry::make('user.email_verified_at')->label('Email terverifikasi')->boolean()
                    ->state(fn (Member $record) => $record->user?->email_verified_at !== null),
                TextEntry::make('education')->label('Pendidikan')->placeholder('-'),
                TextEntry::make('city')->label('Kota')->placeholder('-'),
                TextEntry::make('birth_date')->label('Tanggal lahir')->date('d M Y')->placeholder('-'),
                TextEntry::make('address')->label('Alamat')->placeholder('-')->columnSpanFull(),
                TextEntry::make('created_at')->label('Terdaftar sejak')->date('d M Y'),
                TextEntry::make('registrations_count')->label('Jumlah pendaftaran')->state(fn (Member $record) => $record->registrations()->count()),
                TextEntry::make('completed_count')->label('Kelas selesai')->state(fn (Member $record) => $record->enrollments()->where('status', 'completed')->count()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member_number')->label('No. member')->searchable()->sortable()->copyable(),
                TextColumn::make('user.name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Email')->searchable()->toggleable(),
                TextColumn::make('phone')->label('Telepon')->searchable()->placeholder('-')->toggleable(),
                TextColumn::make('company')->label('Perusahaan')->searchable()->placeholder('-')->toggleable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (?string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('registrations_count')->label('Pendaftaran')->alignCenter()->sortable(),
                TextColumn::make('completed_count')->label('Kelas selesai')->alignCenter()->sortable(),
                TextColumn::make('created_at')->label('Tanggal daftar')->date('d M Y')->sortable(),
            ])
            ->filters(self::filters())
            ->recordActions([ViewAction::make(), EditAction::make()->label('Ubah profil')]);
    }

    /** Filter status, perusahaan, program; dipakai juga oleh tautan ekspor. @return array<int, SelectFilter> */
    public static function filters(bool $withStatus = true): array
    {
        return array_values(array_filter([
            $withStatus ? SelectFilter::make('status')->label('Status')->options(self::STATUS_LABELS) : null,
            SelectFilter::make('company')->label('Perusahaan')->searchable()
                ->options(fn () => Member::query()->whereNotNull('company')->where('company', '!=', '')->distinct()->orderBy('company')->pluck('company', 'company')->all()),
            SelectFilter::make('program')->label('Program')->searchable()
                ->options(fn () => TrainingPackage::query()->orderBy('title')->pluck('title', 'id')->all())
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q->whereHas('enrollments.schedule', fn (Builder $s) => $s->where('package_id', $id)))),
        ]));
    }

    public static function getRelations(): array
    {
        return [
            RegistrationsRelationManager::class,
            EnrollmentsRelationManager::class,
            CertificatesRelationManager::class,
            InvoicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'view' => ViewMember::route('/{record}'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
