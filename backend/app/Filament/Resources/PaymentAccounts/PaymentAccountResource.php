<?php

namespace App\Filament\Resources\PaymentAccounts;

use App\Filament\NavigationGroup;
use App\Filament\Resources\PaymentAccounts\Pages\ManagePaymentAccounts;
use App\Models\PaymentAccount;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Rekening tujuan pembayaran manual (rekening bank / Virtual Account statis). Ditampilkan ke peserta di portal
 * (detail pendaftaran & Riwayat Pembayaran) dan di email tagihan. Izin payments.verify (administrator, finance).
 */
class PaymentAccountResource extends Resource
{
    protected static ?string $model = PaymentAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Rekening pembayaran';

    protected static ?string $modelLabel = 'Rekening pembayaran';

    protected static ?string $pluralModelLabel = 'Rekening pembayaran';

    protected static ?string $recordTitleAttribute = 'bank_name';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('payments.verify') || $u?->isSuperAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label('Jenis')->options(PaymentAccount::TYPES)->default('bank_transfer')->required()->native(false)
                ->helperText('Virtual Account statis: nomor VA tetap dari rekening bisnis PPTI.'),
            TextInput::make('bank_name')->label('Bank')->required()->maxLength(60)->placeholder('BCA, Mandiri, BNI, BRI…'),
            TextInput::make('account_number')->label('Nomor rekening / VA')->required()->maxLength(40)
                ->regex('/^[0-9 .\-]+$/')->validationMessages(['regex' => 'Hanya angka, spasi, titik, atau tanda hubung.']),
            TextInput::make('account_name')->label('Atas nama')->required()->maxLength(120)->default('PT. PPTI - VMES Training Center'),
            Textarea::make('instructions')->label('Petunjuk pembayaran (opsional)')->rows(3)->columnSpanFull()
                ->helperText('Tampil di bawah nomor rekening, mis. langkah bayar VA lewat m-banking/ATM.'),
            TextInput::make('sort')->label('Urutan tampil')->numeric()->default(0)->minValue(0),
            Toggle::make('is_active')->label('Aktif (tampil ke peserta)')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->emptyStateHeading('Belum ada rekening pembayaran')
            ->emptyStateDescription('Tambahkan rekening bank atau nomor Virtual Account resmi PPTI agar tampil di portal peserta dan email tagihan.')
            ->columns([
                TextColumn::make('bank_name')->label('Bank')->weight('bold')->searchable(),
                TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn (string $state) => PaymentAccount::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'virtual_account' ? 'info' : 'gray'),
                TextColumn::make('account_number')->label('Nomor')->copyable()->fontFamily('mono')->searchable(),
                TextColumn::make('account_name')->label('Atas nama')->wrap(),
                ToggleColumn::make('is_active')->label('Aktif'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentAccounts::route('/')];
    }
}
