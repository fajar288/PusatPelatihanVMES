<?php

namespace App\Filament\Resources\ApiClients\Schemas;

use App\Models\ApiClient;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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
            Section::make('Akses')->columns(2)->components([
                CheckboxList::make('scopes')->label('Scope')->options(ApiClient::SCOPES)->required()->columnSpanFull()
                    ->helperText('Berikan scope seminimal mungkin sesuai kebutuhan integrasi.'),
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
                CheckboxList::make('webhook_events')->label('Event')->options(ApiClient::WEBHOOK_EVENTS)->columnSpanFull(),
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
