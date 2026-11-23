<?php

namespace App\Filament\Pages;

use App\Filament\Support\CmsSettingsPage;
use App\Filament\Support\Fields;
use App\Filament\Support\Layout;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Editor terstruktur kunci `company`: isi halaman /tentang (visi & misi, fasilitas, trainer, layanan, kerja sama). */
class CompanyProfileSettings extends CmsSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Profil perusahaan';

    protected static ?string $title = 'Profil perusahaan (halaman Tentang)';

    protected static ?string $slug = 'profil-perusahaan';

    protected static function settingKeys(): array
    {
        return ['company'];
    }

    public function getSaveHint(): string
    {
        return 'Tampil di halaman Tentang. Teks "Tentang kami" di samping video diatur di menu Beranda, tab Tentang.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([Group::make()->statePath('company')->columnSpanFull()->components([
            Section::make('Visi & misi')->description('Bagian "Arah dan Komitmen Kami" di halaman Tentang.')->components([
                Textarea::make('vision')->label('Visi')->required()->rows(3)->maxLength(600),
                Repeater::make('missions')->label('Misi')
                    ->minItems(1)->maxItems(8)->defaultItems(0)->reorderable()->addActionLabel('Tambah misi')
                    ->helperText('Tampil sebagai daftar bernomor di kartu Misi.')
                    ->simple(Textarea::make('text')->label('Misi')->required()->rows(2)->maxLength(400)),
            ]),
            Section::make('Fasilitas')->description('Kartu "Lab Praktik dan Ruang Kelas", grid 3 kolom: paling rapi 3, 6, atau 9 kartu.')->components([
                Repeater::make('facilities')->hiddenLabel()
                    ->minItems(1)->maxItems(9)->defaultItems(0)->reorderable()->collapsible()->addActionLabel('Tambah fasilitas')
                    ->itemLabel(fn (array $state): ?string => filled($state['title'] ?? null) ? (string) $state['title'] : null)
                    ->columns(Layout::cols(2))
                    ->schema([
                        Fields::icon()->required(),
                        TextInput::make('title')->label('Nama fasilitas')->required()->maxLength(80)->distinct(),
                        Textarea::make('desc')->label('Deskripsi')->required()->rows(2)->maxLength(300)->columnSpanFull(),
                    ]),
            ]),
            Section::make('Trainer')->description('Kartu "Instruktur Praktisi Bersertifikasi". Inisial tampil di lingkaran pengganti foto.')->components([
                Repeater::make('trainers')->hiddenLabel()
                    ->minItems(1)->maxItems(12)->defaultItems(0)->reorderable()->addActionLabel('Tambah trainer')
                    ->table([
                        TableColumn::make('Nama')->markAsRequired(),
                        TableColumn::make('Peran')->markAsRequired(),
                        TableColumn::make('Sertifikasi'),
                        TableColumn::make('Inisial')->markAsRequired()->width('7rem'),
                    ])
                    ->schema([
                        TextInput::make('name')->label('Nama')->required()->maxLength(80)->distinct(),
                        TextInput::make('role')->label('Peran')->required()->maxLength(80),
                        TextInput::make('certs')->label('Sertifikasi')->maxLength(160),
                        TextInput::make('initials')->label('Inisial')->required()->maxLength(3),
                    ]),
            ]),
            Section::make('Layanan')->description('Kartu "Pilihan Program Sesuai Kebutuhan Anda" dengan tautan Selengkapnya.')->components([
                Repeater::make('services')->hiddenLabel()
                    ->minItems(1)->maxItems(9)->defaultItems(0)->reorderable()->collapsible()->addActionLabel('Tambah layanan')
                    ->itemLabel(fn (array $state): ?string => filled($state['title'] ?? null) ? (string) $state['title'] : null)
                    ->columns(Layout::cols(3))
                    ->schema([
                        Fields::icon()->required(),
                        TextInput::make('title')->label('Nama layanan')->required()->maxLength(80)->distinct(),
                        TextInput::make('href')->label('Tautan Selengkapnya')->required()->maxLength(255)
                            ->regex('#^(/\S*|https://\S+)$#')
                            ->validationMessages(['regex' => 'Gunakan path situs yang diawali "/" (mis. /jadwal) atau URL https://.'])
                            ->helperText('Mis. /jadwal atau /kontak.'),
                        Textarea::make('desc')->label('Deskripsi')->required()->rows(2)->maxLength(300)->columnSpanFull(),
                    ]),
            ]),
            Section::make('Kerja sama')->description('Daftar "Bentuk kerja sama" di bagian bawah halaman Tentang.')->components([
                Repeater::make('partnership_points')->hiddenLabel()
                    ->minItems(1)->maxItems(8)->defaultItems(0)->reorderable()->addActionLabel('Tambah poin')
                    ->simple(TextInput::make('text')->label('Poin')->required()->maxLength(200)->distinct()),
            ]),
        ])]);
    }
}
