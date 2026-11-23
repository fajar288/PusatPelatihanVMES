<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Resources\SiteSettings\SiteSettingResource;
use App\Filament\Support\Layout;
use App\Models\AuditLog;
use App\Support\JsonValue;
use App\Support\SiteIdentity;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Editor terstruktur untuk pengaturan `site` (identitas & kontak situs): admin tidak perlu menyunting JSON.
 * Struktur yang disimpan tetap sama dengan tipe SiteSettings frontend (lihat SiteIdentity).
 *
 * @property-read Schema $form
 */
class SiteIdentitySettings extends Page
{
    protected string $view = 'filament.pages.site-identity';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::LandingPage;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Identitas & kontak';

    protected static ?string $title = 'Identitas & kontak situs';

    protected static ?string $slug = 'identitas-kontak';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return SiteSettingResource::canViewAny();
    }

    public function mount(): void
    {
        $this->form->fill(SiteIdentity::current());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('WhatsApp & kontak')
                ->description('Dipakai tombol WhatsApp mengambang, header, footer, halaman Kontak dan Bantuan, data terstruktur (JSON-LD), invoice PDF, dan chatbot.')
                ->columns(Layout::cols(2))->components([
                    TextInput::make('waNumber')->label('Nomor WhatsApp')->required()->maxLength(15)
                        ->regex(SiteIdentity::WA_NUMBER_REGEX)
                        ->validationMessages(['regex' => 'Gunakan format internasional tanpa "+", spasi, atau tanda hubung, diawali 62. Contoh: 6285121251285.'])
                        ->placeholder('6285121251285')
                        ->helperText('Format 62..., tanpa 0 di depan. Dipakai untuk tautan wa.me.'),
                    TextInput::make('waDisplay')->label('Tampilan nomor')->required()->maxLength(30)->placeholder('0851-2125-1285')
                        ->helperText('Cara nomor ditulis di situs.'),
                    TextInput::make('waDefaultText')->label('Pesan pembuka WhatsApp')->required()->maxLength(200)->columnSpanFull()
                        ->helperText('Teks awal saat pengunjung menekan tombol WhatsApp umum.'),
                    Textarea::make('address')->label('Alamat')->required()->rows(2)->maxLength(500)->columnSpanFull(),
                    TextInput::make('mapsUrl')->label('Tautan Google Maps')->required()->url()->maxLength(500)->columnSpanFull()
                        ->rule('starts_with:https://')
                        ->helperText('Buka lokasi di Google Maps, pilih Bagikan, lalu salin tautannya.'),
                ]),
            Section::make('Identitas situs')->columns(Layout::cols(2))->components([
                TextInput::make('name')->label('Nama situs')->required()->maxLength(120),
                TextInput::make('shortName')->label('Nama singkat')->required()->maxLength(60),
                TextInput::make('company')->label('Nama perusahaan')->required()->maxLength(160)->columnSpanFull(),
                TextInput::make('tagline')->label('Tagline')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->label('Deskripsi situs (SEO)')->required()->rows(3)->maxLength(500)->columnSpanFull(),
                Textarea::make('footerDescription')->label('Deskripsi footer')->required()->rows(3)->maxLength(600)->columnSpanFull(),
                TextInput::make('youtubeProfileId')->label('ID video profil YouTube')->maxLength(11)
                    ->regex(SiteIdentity::YOUTUBE_ID_REGEX)
                    ->validationMessages(['regex' => 'ID video YouTube terdiri dari 11 karakter, mis. IClaATbg--A (bagian setelah v= pada tautan).'])
                    ->helperText('Bagian setelah "v=" pada tautan YouTube. Kosongkan bila tidak ada.'),
            ]),
            Section::make('Media sosial')->description('Ikon tampil di footer sesuai urutan.')->components([
                Repeater::make('socials')->hiddenLabel()->defaultItems(0)->addActionLabel('Tambah media sosial')->reorderable()
                    ->table([
                        TableColumn::make('Ikon')->markAsRequired()->width('12rem'),
                        TableColumn::make('URL')->markAsRequired(),
                        TableColumn::make('Label')->width('14rem'),
                    ])
                    ->schema([
                        Select::make('icon')->label('Ikon')->options(SiteIdentity::SOCIAL_ICONS)->required()->native(false),
                        TextInput::make('href')->label('URL')->url()->required()->maxLength(255),
                        TextInput::make('label')->label('Label')->maxLength(60),
                    ]),
            ]),
        ]);
    }

    public function save(): void
    {
        $before = SiteIdentity::current();
        $value = SiteIdentity::save($this->form->getState());
        $changed = array_keys(array_filter($value, fn ($v, $k) => ! JsonValue::same($before[$k] ?? null, $v), ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            AuditLog::record('settings.site_updated', null, array_intersect_key($before, array_flip($changed)), array_intersect_key($value, array_flip($changed)));
        }
        $this->form->fill($value);
        Notification::make()->title('Identitas & kontak disimpan. Situs diperbarui dalam beberapa detik.')->success()->send();
    }
}
