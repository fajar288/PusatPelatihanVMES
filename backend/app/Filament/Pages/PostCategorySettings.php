<?php

namespace App\Filament\Pages;

use App\Filament\Support\CmsSettingsPage;
use App\Filament\Support\Layout;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Editor terstruktur kunci `post_categories`: judul dan deskripsi halaman kategori artikel (/tutorial, /berita,
 * /kegiatan, /dokumentasi). Slug dan kategori tetap karena menjadi alamat halaman dan jenis postingan, jadi daftar
 * tidak bisa ditambah, dihapus, atau diurutkan ulang (lihat CmsSettings::lockCategories).
 */
class PostCategorySettings extends CmsSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Kategori artikel';

    protected static ?string $title = 'Kategori artikel';

    protected static ?string $slug = 'kategori-artikel';

    protected static function settingKeys(): array
    {
        return ['post_categories'];
    }

    public function getSaveHint(): string
    {
        return 'Judul dan deskripsi tampil di bagian atas halaman kategori dan sebagai deskripsi halaman di hasil pencarian.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Halaman kategori')
                ->description('Alamat halaman dan jenis postingan tidak bisa diubah. Postingan diberi kategori di menu Artikel.')
                ->components([
                    Repeater::make('post_categories')->hiddenLabel()
                        ->addable(false)->deletable(false)->reorderable(false)
                        ->itemLabel(fn (array $state): ?string => isset($state['slug']) ? '/'.$state['slug'] : null)
                        ->columns(Layout::cols(2))
                        ->schema([
                            TextInput::make('slug')->label('Alamat halaman')->prefix('/')->disabled()->dehydrated(),
                            TextInput::make('category')->label('Kategori postingan')->disabled()->dehydrated(),
                            TextInput::make('title')->label('Judul halaman')->required()->maxLength(80)
                                ->helperText('Judul besar di bagian atas halaman kategori.'),
                            Textarea::make('desc')->label('Deskripsi')->required()->rows(2)->maxLength(300)
                                ->helperText('Teks di bawah judul, juga dipakai sebagai deskripsi halaman untuk mesin pencari.'),
                        ]),
                ]),
        ]);
    }
}
