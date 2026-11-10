<?php

namespace App\Filament\Support;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/** Komponen form yang dipakai berulang di resource CMS. */
final class Fields
{
    /** Unggah gambar ke disk default (public/S3), di-resize di sisi klien agar ringan. */
    public static function image(string $name, string $dir, int $maxWidth = 1600): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk(config('filesystems.default'))
            ->directory($dir)
            ->visibility('public')
            ->imageResizeMode('contain')
            ->imageResizeTargetWidth((string) $maxWidth)
            ->imageResizeTargetHeight((string) $maxWidth)
            ->maxSize(4096)
            ->helperText('JPG/PNG/WebP maks. 4 MB; otomatis diperkecil ke '.$maxWidth.'px.');
    }

    /** Judul yang otomatis mengisi slug saat dibuat. */
    public static function title(string $name = 'title', string $slugField = 'slug'): TextInput
    {
        return TextInput::make($name)
            ->required()
            ->maxLength(255)
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Set $set, ?string $state, ?string $operation) => $operation === 'create' ? $set($slugField, Str::slug($state ?? '')) : null);
    }

    public static function slug(string $table): TextInput
    {
        return TextInput::make('slug')
            ->required()
            ->maxLength(255)
            ->rule('alpha_dash')
            ->unique($table, 'slug', ignoreRecord: true)
            ->helperText('Bagian URL, huruf kecil dan tanda hubung.');
    }

    public static function status(): Select
    {
        return Select::make('status')
            ->options(['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'])
            ->default('draft')
            ->required()
            ->native(false);
    }

    public static function mode(): Select
    {
        return Select::make('mode')
            ->options(['online' => 'online', 'offline' => 'offline', 'hybrid' => 'hybrid', 'online_offline' => 'online / offline'])
            ->default('offline')
            ->required()
            ->native(false);
    }

    /** Ikon sprite tema (39 simbol lucide-style). */
    public static function icon(string $name = 'icon'): Select
    {
        $icons = ['users', 'shield', 'cloud', 'laptop', 'award', 'grad', 'trophy', 'spark', 'msg', 'arr', 'network', 'server', 'db', 'code', 'brief', 'boxes', 'cpu', 'bot', 'factory', 'chart', 'wrench', 'zap', 'rocket', 'cal', 'globe', 'arrl', 'clock', 'monitor', 'star', 'quote', 'check', 'pin', 'phone', 'mail'];

        return Select::make($name)->options(array_combine($icons, $icons))->searchable()->native(false)->helperText('Nama ikon sprite tema website.');
    }
}
