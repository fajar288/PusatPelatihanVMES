<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/** Berkas materi (PDF/PPT/DOC/XLS/video/gambar) & tautan per lesson dengan izin unduh (PRD §5.8). */
class ResourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'resources';

    protected static ?string $title = 'Berkas & tautan';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label('Jenis')->options(['file' => 'Berkas', 'link' => 'Tautan'])->default('file')->required()->native(false)->live(),
            TextInput::make('title')->label('Judul')->required()->maxLength(255),
            FileUpload::make('file_path')->label('Berkas')->visible(fn (Get $get) => $get('type') === 'file')->required(fn (Get $get) => $get('type') === 'file')
                ->disk(config('filesystems.default'))->directory('lms/resources')->visibility('public')->storeFileNamesIn('file_name')->maxSize(102400)
                ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/*', 'video/mp4', 'video/webm', 'audio/*', 'application/zip', 'text/plain'])
                ->helperText('PDF, Office, gambar, MP4/WebM, ZIP; maks. 100 MB.')->columnSpanFull(),
            TextInput::make('url')->label('URL')->url()->visible(fn (Get $get) => $get('type') === 'link')->required(fn (Get $get) => $get('type') === 'link')->columnSpanFull(),
            Toggle::make('allow_download')->label('Boleh diunduh')->default(true)->helperText('Nonaktif = hanya dibaca di aplikasi (viewer).'),
            TextInput::make('sort_order')->label('Urutan')->numeric()->default(fn () => ($this->getOwnerRecord()->resources()->max('sort_order') ?? 0) + 1),
        ]);
    }

    public function table(Table $table): Table
    {
        $meta = function (array $data): array {
            if (($data['type'] ?? 'file') === 'file' && ! empty($data['file_path'])) {
                $disk = Storage::disk(config('filesystems.default'));
                $data['mime'] = $disk->exists($data['file_path']) ? $disk->mimeType($data['file_path']) : null;
                $data['size'] = $disk->exists($data['file_path']) ? $disk->size($data['file_path']) : null;
            }

            return $data;
        };

        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('title')->label('Judul'),
                TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn ($state) => $state === 'link' ? 'Tautan' : 'Berkas'),
                TextColumn::make('file_name')->label('Nama berkas')->placeholder('-')->description(fn ($record) => $record->type === 'link' ? $record->url : ($record->size ? round($record->size / 1024).' KB' : null)),
                IconColumn::make('allow_download')->label('Unduh')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Tambah')->mutateDataUsing($meta)])
            ->recordActions([EditAction::make()->mutateDataUsing($meta), DeleteAction::make()]);
    }
}
