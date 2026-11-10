<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Halaman statis')->columns(2)->components([
                    Fields::title(),
                    Fields::slug('pages'),
                    RichEditor::make('body')
                        ->label('Isi')
                        ->fileAttachmentsDisk(config('filesystems.default'))
                        ->fileAttachmentsDirectory('pages')
                        ->fileAttachmentsVisibility('public')
                        ->columnSpanFull(),
                    Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->default('published')->required()->native(false),
                    DateTimePicker::make('published_at')->seconds(false)->native(false),
                    TextInput::make('seo_title')->maxLength(255),
                    Textarea::make('seo_description')->rows(2)->maxLength(320)->columnSpanFull(),
                ]),
            ]);
    }
}
