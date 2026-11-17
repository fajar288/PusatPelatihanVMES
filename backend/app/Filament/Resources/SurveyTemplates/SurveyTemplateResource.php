<?php

namespace App\Filament\Resources\SurveyTemplates;

use App\Filament\NavigationGroup;
use App\Filament\Resources\SurveyTemplates\Pages\ManageSurveyTemplates;
use App\Models\SurveyResponse;
use App\Models\SurveyTemplate;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Template survei evaluasi pasca-kelas: pertanyaan skala 1-5 dan komentar. Izin monitoring.manage. */
class SurveyTemplateResource extends Resource
{
    protected static ?string $model = SurveyTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsDelivery;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Template survei';

    protected static ?string $modelLabel = 'Template survei';

    protected static ?string $pluralModelLabel = 'Template survei';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('monitoring.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(255),
            Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            Textarea::make('description')->label('Keterangan')->rows(2)->columnSpanFull(),
            Toggle::make('is_default')->label('Dipakai untuk undangan baru')->helperText('Template aktif bertanda ini dipakai saat mengundang peserta. Survei yang sudah dikirim tidak berubah.')->columnSpanFull(),
            Repeater::make('questions')->label('Pertanyaan')->columnSpanFull()->minItems(1)->reorderable()->addActionLabel('Tambah pertanyaan')
                ->default(SurveyTemplate::DEFAULT_QUESTIONS)
                // Satu pertanyaan per baris: Kode | Jenis | Pertanyaan | Wajib (menumpuk di bawah 1024 px)
                ->table([
                    TableColumn::make('Kode')->markAsRequired()->width('8rem'),
                    TableColumn::make('Jenis')->markAsRequired()->width('10rem'),
                    TableColumn::make('Pertanyaan')->markAsRequired(),
                    TableColumn::make('Wajib')->width('5rem')->alignment(Alignment::Center),
                ])
                ->schema([
                    TextInput::make('key')->label('Kode')->required()->alphaDash()->maxLength(40),
                    Select::make('type')->label('Jenis')->options(SurveyTemplate::TYPES)->required()->native(false),
                    TextInput::make('label')->label('Pertanyaan')->required()->maxLength(255),
                    Toggle::make('required')->label('Wajib')->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('bold')->description(fn (SurveyTemplate $record) => count((array) $record->questions).' pertanyaan'),
                IconColumn::make('is_default')->label('Dipakai')->boolean(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('updated_at')->label('Diubah')->dateTime('d M Y H:i'),
            ])
            ->recordActions([
                EditAction::make()->modalWidth(Width::FiveExtraLarge),
                DeleteAction::make()->visible(fn (SurveyTemplate $record) => ! SurveyResponse::query()->where('template_id', $record->id)->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSurveyTemplates::route('/')];
    }
}
