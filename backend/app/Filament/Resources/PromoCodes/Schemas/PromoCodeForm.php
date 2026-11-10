<?php

namespace App\Filament\Resources\PromoCodes\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PromoCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kode promo')->columns(3)->components([
                TextInput::make('code')->label('Kode')->required()->maxLength(40)->unique('promo_codes', 'code', ignoreRecord: true)
                    ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) $state))),
                TextInput::make('name')->label('Nama / keterangan')->required()->maxLength(255)->columnSpan(2),
                Select::make('type')->label('Jenis')->options(['percent' => 'Persen (%)', 'fixed' => 'Nominal (Rp)'])->default('percent')->required()->native(false),
                TextInput::make('value')->label('Nilai')->numeric()->minValue(0)->required(),
                TextInput::make('max_discount')->label('Diskon maks. (Rp)')->numeric()->minValue(0),
                TextInput::make('min_total')->label('Minimum total (Rp)')->numeric()->minValue(0),
                DatePicker::make('valid_from')->label('Berlaku dari')->native(false),
                DatePicker::make('valid_until')->label('Berlaku sampai')->native(false),
                TextInput::make('max_uses')->label('Kuota pemakaian')->numeric()->minValue(1),
                TextInput::make('used_count')->label('Terpakai')->numeric()->default(0)->disabled()->dehydrated(false),
                Select::make('package_id')->label('Hanya untuk paket')->relationship('package', 'title')->searchable()->preload(),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),
        ]);
    }
}
