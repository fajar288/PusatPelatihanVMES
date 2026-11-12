<?php

namespace App\Filament\Resources\ApiClients\Schemas;

use App\Models\ApiClient;
use App\Models\CorporateUser;
use App\Models\Organization;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ApiClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Klien')->columns(2)->components([
                TextInput::make('name')->label('Nama integrasi')->required()->maxLength(255)->placeholder('mis. Portal HR PT Contoh'),
                TextInput::make('organization')->label('Organisasi')->maxLength(255),
                TextInput::make('contact_email')->label('Email kontak teknis')->email()->maxLength(255),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                Textarea::make('description')->label('Keterangan / tujuan integrasi')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Organisasi (API enterprise)')->columns(2)
                ->description('Klien enterprise hanya dapat membaca/mengubah data organisasi ini dan bertindak atas nama satu pengguna perusahaan aktif: peran & batas unit pengguna itu ikut membatasi scope. Bila pengguna itu dinonaktifkan (mis. resign), akses klien otomatis terputus.')
                ->components([
                    Select::make('organization_id')->label('Organisasi terikat')->searchable()->preload()->live()
                        ->options(fn () => Organization::query()->orderBy('legal_name')->get()->mapWithKeys(fn (Organization $o) => [$o->id => $o->code.' — '.$o->name()])->all())
                        ->afterStateUpdated(fn (Set $set) => $set('corporate_user_id', null))
                        ->helperText('Kosongkan untuk klien mitra/agregator (hanya scope umum).'),
                    Select::make('corporate_user_id')->label('Bertindak atas nama')->searchable()
                        ->options(fn (Get $get) => $get('organization_id')
                            ? CorporateUser::query()->active()->where('organization_id', $get('organization_id'))->with(['user:id,name,email', 'unit'])->get()
                                ->mapWithKeys(fn (CorporateUser $cu) => [$cu->id => ($cu->user?->name ?? '?').' ('.(CorporateUser::ROLES[$cu->role] ?? $cu->role).($cu->unit ? ', '.$cu->unit->label() : '').')'])->all()
                            : [])
                        ->required(fn (Get $get) => filled($get('organization_id')))
                        ->disabled(fn (Get $get) => blank($get('organization_id')))
                        ->helperText('Pengguna perusahaan aktif organisasi tsb. Disarankan akun layanan khusus integrasi.'),
                ]),
            Section::make('Akses')->columns(2)->components([
                CheckboxList::make('scopes')->label('Scope')->required()->columnSpanFull()->columns(2)
                    ->options(fn () => ApiClient::SCOPES + collect(ApiClient::ENTERPRISE_SCOPES)->mapWithKeys(fn ($d, $s) => [$s => "[Enterprise] $s"])->all())
                    ->descriptions(ApiClient::allScopes())
                    ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                        if (array_intersect((array) $value, array_keys(ApiClient::ENTERPRISE_SCOPES)) !== [] && (blank($get('organization_id')) || blank($get('corporate_user_id')))) {
                            $fail('Scope [Enterprise] membutuhkan organisasi terikat dan pengguna perusahaan yang diwakili.');
                        }
                    })
                    ->helperText('Berikan scope seminimal mungkin sesuai kebutuhan integrasi. Scope [Enterprise] juga dibatasi peran pengguna perusahaan yang diwakili.'),
                TextInput::make('rate_limit')->label('Batas permintaan / menit')->numeric()->minValue(1)->maxValue(6000)
                    ->default(fn () => config('services.public_api.default_rate_limit', 60))->required(),
                DateTimePicker::make('expires_at')->label('Kedaluwarsa')->native(false)->helperText('Kosongkan bila tidak kedaluwarsa.'),
                TagsInput::make('allowed_ips')->label('IP yang diizinkan')->placeholder('203.0.113.10 atau 203.0.113.0/24')->columnSpanFull()
                    ->helperText('Kosongkan untuk mengizinkan semua IP.')
                    ->nestedRecursiveRules([fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        [$ip, $mask] = array_pad(explode('/', (string) $value, 2), 2, null);
                        $isV6 = str_contains((string) $ip, ':');
                        if (! filter_var($ip, FILTER_VALIDATE_IP) || ($mask !== null && (! ctype_digit($mask) || (int) $mask > ($isV6 ? 128 : 32)))) {
                            $fail("\"$value\" bukan alamat IP/CIDR yang valid.");
                        }
                    }]),
            ]),
            Section::make('Webhook')->columns(2)->components([
                TextInput::make('webhook_url')->label('URL webhook')->url()->maxLength(255)->columnSpanFull()
                    ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        if ($value && app()->isProduction() && ! str_starts_with($value, 'https://')) {
                            $fail('URL webhook wajib HTTPS.');
                        }
                    })
                    ->helperText('PPTI mengirim POST JSON bertanda tangan HMAC-SHA256 (header X-PPTI-Signature). Secret dibuat otomatis.'),
                CheckboxList::make('webhook_events')->label('Event')->columnSpanFull()
                    ->options(fn () => ApiClient::WEBHOOK_EVENTS + collect(ApiClient::ENTERPRISE_WEBHOOK_EVENTS)->mapWithKeys(fn ($d, $e) => [$e => "[Enterprise] $e"])->all())
                    ->descriptions(ApiClient::allWebhookEvents())
                    ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                        if (array_intersect((array) $value, array_keys(ApiClient::ENTERPRISE_WEBHOOK_EVENTS)) !== [] && (blank($get('organization_id')) || blank($get('corporate_user_id')))) {
                            $fail('Event [Enterprise] membutuhkan organisasi terikat dan pengguna perusahaan yang diwakili.');
                        }
                    })
                    ->helperText('Event enterprise hanya dikirim bila klien juga punya scope baca yang sesuai (mis. enrollments.read untuk enrollment.*) dan subjeknya dalam batas unit pengguna yang diwakili.'),
            ]),
            Section::make('Kredensial & pemakaian')->columns(3)->visibleOn('edit')->components([
                TextEntry::make('key_prefix')->label('API key')->state(fn (?ApiClient $record) => $record?->maskedKey())->fontFamily('mono'),
                TextEntry::make('key_rotated_at')->label('Key terakhir diganti')->dateTime('d M Y H:i')->placeholder('Belum pernah'),
                TextEntry::make('webhook_secret_state')->label('Secret webhook')->state(fn (?ApiClient $record) => filled($record?->webhook_secret) ? 'Sudah dibuat' : 'Belum ada'),
                TextEntry::make('last_used_at')->label('Terakhir dipakai')->dateTime('d M Y H:i')->placeholder('Belum pernah'),
                TextEntry::make('last_used_ip')->label('IP terakhir')->placeholder('-'),
                TextEntry::make('requests_24h')->label('Permintaan 24 jam')
                    ->state(fn (?ApiClient $record) => $record ? $record->requestLogs()->where('created_at', '>=', now()->subDay())->count() : 0),
            ]),
        ]);
    }
}
