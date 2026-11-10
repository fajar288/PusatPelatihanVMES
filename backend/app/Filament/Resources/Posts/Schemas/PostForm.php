<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Filament\Support\Fields;
use App\Models\Post;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Konten')->columns(2)->components([
                    Fields::title(),
                    Fields::slug('posts'),
                    Select::make('type')
                        ->label('Kategori')
                        ->options(collect(Post::TYPES)->map(fn ($t) => $t['label'])->all())
                        ->default('news')
                        ->required()
                        ->native(false),
                    Fields::status(),
                    Textarea::make('excerpt')->label('Ringkasan')->rows(3)->columnSpanFull(),
                    RichEditor::make('body')
                        ->label('Isi')
                        ->fileAttachmentsDisk(config('filesystems.default'))
                        ->fileAttachmentsDirectory('posts/attachments')
                        ->fileAttachmentsVisibility('public')
                        ->columnSpanFull(),
                    Fields::image('thumbnail', 'posts')->label('Thumbnail')->columnSpanFull(),
                    TagsInput::make('tags')->label('Tag')->columnSpanFull(),
                ]),
                Section::make('Publikasi & SEO')->columns(2)->components([
                    DateTimePicker::make('published_at')->label('Tanggal terbit')->seconds(false)->native(false),
                    TextInput::make('author_name')->label('Penulis (tampil)')->maxLength(255),
                    Select::make('author_id')->label('Akun penulis')->relationship('author', 'name')->searchable()->preload(),
                    TextInput::make('seo_title')->maxLength(255),
                    Textarea::make('seo_description')->rows(2)->maxLength(320)->columnSpanFull(),
                ])->collapsible(),
            ]);
    }
}
