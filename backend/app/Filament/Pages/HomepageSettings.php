<?php

namespace App\Filament\Pages;

use App\Filament\Support\CmsSettingsPage;
use App\Filament\Support\Fields;
use App\Filament\Support\Layout;
use App\Support\CmsSettings;
use App\Support\HeroMedia;
use BackedEnum;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Editor terstruktur beranda: kunci hero, stats, about, why, dan cta (satu tab per kunci, urut seperti tampil di
 * beranda), ditambah slide foto & logo Partner Resmi dari tabel Banner dan Mitra. Batas jumlah item mengikuti grid tema.
 */
class HomepageSettings extends CmsSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Beranda';

    protected static ?string $title = 'Konten beranda';

    protected static ?string $slug = 'konten-beranda';

    /** Fitur di bawah judul hero: grid 4 kolom (2 kolom di ponsel). */
    public const MAX_FEATURES = 4;

    /** Statistik di bawah hero: grid 5 kolom. */
    public const MAX_STATS = 5;

    /** Logo Partner Resmi: kartu kaca 2 x 2. */
    public const MAX_OFFICIAL_PARTNERS = 4;

    public const MAX_SLIDES = 8;

    /** Kartu "Mengapa PPTI": grid 3 kolom, paling rapi kelipatan 3. */
    public const MAX_WHY = 9;

    /** Poin di panel kaca CTA. */
    public const MAX_CTA_POINTS = 6;

    protected static function settingKeys(): array
    {
        return CmsSettings::HOME_KEYS;
    }

    protected function loadState(): array
    {
        return parent::loadState() + ['heroMedia' => ['slides' => HeroMedia::slides(), 'officialPartners' => HeroMedia::officialPartners()]];
    }

    protected function saveExtra(array $state): bool
    {
        return HeroMedia::sync((array) ($state['heroMedia']['slides'] ?? []), (array) ($state['heroMedia']['officialPartners'] ?? []));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('Beranda')->key('beranda')->persistTabInQueryString('tab')->tabs([
                Tab::make('Hero')->key('hero')->icon(Heroicon::OutlinedSparkles)->schema([
                    Section::make('Teks hero')->statePath('hero')
                        ->description('Bagian paling atas beranda, di sebelah kiri slide foto.')
                        ->columns(Layout::cols(2))->components([
                            TextInput::make('pill')->label('Label kecil di atas judul')->required()->maxLength(120)->columnSpanFull()
                                ->helperText('Tampil dalam kapsul hijau dengan ikon kilau.'),
                            TextInput::make('titleLine1')->label('Judul baris 1')->required()->maxLength(80)
                                ->helperText('Baris pertama judul besar (warna gelap).'),
                            TextInput::make('titleLine2')->label('Judul baris 2')->required()->maxLength(80)
                                ->helperText('Baris kedua judul besar (warna gradasi).'),
                            Textarea::make('lead')->label('Paragraf pembuka')->required()->rows(3)->maxLength(400)->columnSpanFull()
                                ->helperText('Teks di bawah judul, di atas daftar fitur.'),
                            Repeater::make('features')->label('Fitur singkat')->columnSpanFull()
                                ->helperText('Ikon dan teks pendek di bawah paragraf pembuka. Maksimal '.self::MAX_FEATURES.' agar muat satu baris.')
                                ->minItems(1)->maxItems(self::MAX_FEATURES)->defaultItems(0)->reorderable()->addActionLabel('Tambah fitur')
                                ->table([
                                    TableColumn::make('Ikon')->markAsRequired()->width('18rem'),
                                    TableColumn::make('Teks')->markAsRequired(),
                                ])
                                ->schema([
                                    Fields::icon()->required()->helperText(null),
                                    TextInput::make('label')->label('Teks')->required()->maxLength(60)->distinct(),
                                ]),
                        ]),
                    Section::make('Kartu angka di foto')->statePath('hero.glassStat')
                        ->description('Kartu kaca di pojok kiri bawah slide foto, mis. "12.000+ Lulusan".')
                        ->columns(Layout::cols(3))->components([
                            Fields::icon()->required(),
                            TextInput::make('title')->label('Judul')->required()->maxLength(40),
                            TextInput::make('sub')->label('Keterangan')->required()->maxLength(60),
                        ]),
                    Section::make('Slide foto')->statePath('heroMedia')
                        ->description('Foto bergantian di sisi kanan hero. Jadwal tayang dan tautan per slide diatur di menu Banner.')
                        ->components([
                            Repeater::make('slides')->hiddenLabel()
                                ->minItems(1)->maxItems(self::MAX_SLIDES)->defaultItems(0)->reorderable()->addActionLabel('Tambah slide')
                                ->itemLabel(fn (array $state): ?string => filled($state['alt'] ?? null) ? (string) $state['alt'] : null)
                                ->columns(Layout::cols(2))
                                ->schema([
                                    Hidden::make('id'),
                                    ...Fields::imageWithUrl('image', 'banners', 1920, 'Foto'),
                                    TextInput::make('alt')->label('Teks alternatif')->maxLength(255)->columnSpanFull()
                                        ->helperText('Deskripsi foto untuk pembaca layar, mis. "Peserta praktik PLC di lab".'),
                                ]),
                        ]),
                    Section::make('Partner Resmi')->statePath('heroMedia')
                        ->description('Logo di kartu "Partner Resmi" pada slide foto. Menghapus logo di sini tidak menghapus mitra dari halaman Mitra.')
                        ->components([
                            Repeater::make('officialPartners')->hiddenLabel()
                                ->maxItems(self::MAX_OFFICIAL_PARTNERS)->defaultItems(0)->reorderable()->addActionLabel('Tambah mitra resmi')
                                ->helperText('Maksimal '.self::MAX_OFFICIAL_PARTNERS.' logo (kartu 2 x 2).')
                                ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null) ? (string) $state['name'] : null)
                                ->columns(Layout::cols(3))
                                ->schema([
                                    Hidden::make('id'),
                                    TextInput::make('name')->label('Nama mitra')->required()->maxLength(120)->distinct(),
                                    ...Fields::imageWithUrl('logo', 'partners', 1200, 'Logo'),
                                ]),
                        ]),
                ]),

                Tab::make('Statistik')->key('stats')->icon(Heroicon::OutlinedChartBar)->schema([
                    Section::make('Statistik')
                        ->description('Bar angka putih tepat di bawah hero. Angka dihitung naik saat bar terlihat.')
                        ->components([
                            Repeater::make('stats')->hiddenLabel()
                                ->minItems(2)->maxItems(self::MAX_STATS)->defaultItems(0)->reorderable()->addActionLabel('Tambah statistik')
                                ->helperText('Maksimal '.self::MAX_STATS.' angka (satu baris di layar lebar, dua kolom di ponsel).')
                                ->table([
                                    TableColumn::make('Ikon')->markAsRequired()->width('18rem'),
                                    TableColumn::make('Angka')->markAsRequired()->width('9rem'),
                                    TableColumn::make('Akhiran')->width('9rem'),
                                    TableColumn::make('Keterangan')->markAsRequired(),
                                ])
                                ->schema([
                                    Fields::icon()->required()->helperText(null),
                                    TextInput::make('n')->label('Angka')->required()->integer()->minValue(0)->maxValue(999999999),
                                    TextInput::make('suffix')->label('Akhiran')->maxLength(20)->placeholder('+'),
                                    TextInput::make('label')->label('Keterangan')->required()->maxLength(40)->distinct(),
                                ]),
                        ]),
                ]),

                Tab::make('Tentang')->key('about')->icon(Heroicon::OutlinedInformationCircle)->schema([
                    Section::make('Tentang kami')->statePath('about')
                        ->description('Bagian di samping video profil YouTube, di beranda dan di halaman Tentang. ID video diatur di menu Identitas & kontak.')
                        ->columns(Layout::cols(2))->components([
                            TextInput::make('eyebrow')->label('Label kecil')->required()->maxLength(60)->columnSpanFull()
                                ->helperText('Teks hijau kecil di atas judul.'),
                            TextInput::make('titlePrefix')->label('Judul (bagian awal)')->required()->maxLength(120)
                                ->helperText('Tulis spasi di akhir bila perlu, mis. "Tentang ".'),
                            TextInput::make('titleEm')->label('Judul (bagian ditekankan)')->required()->maxLength(120)
                                ->helperText('Disambung langsung setelah bagian awal, tampil berwarna.'),
                            Repeater::make('paragraphs')->label('Paragraf')->columnSpanFull()
                                ->minItems(1)->maxItems(4)->defaultItems(0)->reorderable()->addActionLabel('Tambah paragraf')
                                ->simple(Textarea::make('text')->label('Paragraf')->required()->rows(3)->maxLength(800)),
                            TextInput::make('tagline')->label('Tagline')->required()->maxLength(160)->columnSpanFull()
                                ->helperText('Kalimat penutup bergaris di bawah paragraf.'),
                        ]),
                ]),

                Tab::make('Keunggulan')->key('why')->icon(Heroicon::OutlinedCheckBadge)->schema([
                    Section::make('Mengapa memilih kami')->statePath('why')
                        ->description('Judul dan kartu keunggulan di tengah beranda.')
                        ->columns(Layout::cols(2))->components([
                            TextInput::make('eyebrow')->label('Label kecil')->required()->maxLength(60),
                            TextInput::make('title')->label('Judul')->required()->maxLength(120),
                            Textarea::make('sub')->label('Subjudul')->required()->rows(2)->maxLength(300)->columnSpanFull(),
                            Repeater::make('items')->label('Kartu keunggulan')->columnSpanFull()
                                ->helperText('Grid 3 kolom: paling rapi 3, 6, atau 9 kartu.')
                                ->minItems(3)->maxItems(self::MAX_WHY)->defaultItems(0)->reorderable()->addActionLabel('Tambah kartu')
                                ->itemLabel(fn (array $state): ?string => filled($state['title'] ?? null) ? (string) $state['title'] : null)
                                ->collapsible()
                                ->columns(Layout::cols(2))
                                ->schema([
                                    Fields::icon()->required(),
                                    TextInput::make('title')->label('Judul kartu')->required()->maxLength(80)->distinct(),
                                    Textarea::make('desc')->label('Deskripsi')->required()->rows(2)->maxLength(300)->columnSpanFull(),
                                ]),
                        ]),
                ]),

                Tab::make('Ajakan (CTA)')->key('cta')->icon(Heroicon::OutlinedMegaphone)->schema([
                    Section::make('Banner ajakan')->statePath('cta')
                        ->description('Banner hijau gelap di bagian bawah beranda, dengan tombol Daftar Sekarang dan WhatsApp.')
                        ->columns(Layout::cols(2))->components([
                            TextInput::make('eyebrow')->label('Label kecil')->required()->maxLength(60),
                            TextInput::make('title')->label('Judul')->required()->maxLength(120),
                            Textarea::make('desc')->label('Deskripsi')->required()->rows(3)->maxLength(400)->columnSpanFull(),
                            Repeater::make('points')->label('Poin di panel kanan')->columnSpanFull()
                                ->helperText('Daftar bertanda centang di panel kaca. Maksimal '.self::MAX_CTA_POINTS.'.')
                                ->minItems(1)->maxItems(self::MAX_CTA_POINTS)->defaultItems(0)->reorderable()->addActionLabel('Tambah poin')
                                ->simple(TextInput::make('text')->label('Poin')->required()->maxLength(80)->distinct()),
                        ]),
                ]),
            ]),
        ]);
    }
}
