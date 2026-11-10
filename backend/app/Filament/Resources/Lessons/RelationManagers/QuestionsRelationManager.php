<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Models\QuizQuestion;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Soal kuis/pre-test/post-test (hanya untuk lesson bertipe kuis). */
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Soal';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'quiz';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label('Jenis soal')->options(QuizQuestion::TYPES)->default('single')->required()->native(false)->live(),
            TextInput::make('points')->label('Poin')->numeric()->default(1)->minValue(0),
            Textarea::make('question')->label('Pertanyaan')->required()->rows(3)->columnSpanFull(),
            Repeater::make('options')->label('Pilihan jawaban')->visible(fn (Get $get) => in_array($get('type'), ['single', 'multiple'], true))->columnSpanFull()
                ->schema([
                    TextInput::make('key')->label('Kode')->required()->maxLength(5)->placeholder('a'),
                    TextInput::make('text')->label('Teks pilihan')->required()->maxLength(500)->columnSpan(3),
                ])->columns(4)->defaultItems(4)->addActionLabel('Tambah pilihan')->reorderable(false),
            TagsInput::make('answer')->label('Kunci jawaban')->required()->columnSpanFull()
                ->helperText(fn (Get $get) => match ($get('type')) {
                    'multiple' => 'Masukkan semua kode pilihan yang benar (mis. a, c).',
                    'true_false' => 'Isi "true" (Benar) atau "false" (Salah).',
                    'short' => 'Satu atau beberapa jawaban yang diterima (tidak peka huruf besar/kecil).',
                    default => 'Kode pilihan yang benar (mis. b).',
                }),
            Textarea::make('explanation')->label('Pembahasan (tampil setelah submit)')->rows(2)->columnSpanFull(),
            TextInput::make('sort_order')->label('Urutan')->numeric()->default(fn () => ($this->getOwnerRecord()->questions()->max('sort_order') ?? 0) + 1),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('question')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('question')->label('Pertanyaan')->limit(80)->wrap(),
                TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn ($state) => QuizQuestion::TYPES[$state] ?? $state),
                TextColumn::make('answer')->label('Kunci')->badge(),
                TextColumn::make('points')->label('Poin'),
            ])
            ->headerActions([CreateAction::make()->label('Tambah soal')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
