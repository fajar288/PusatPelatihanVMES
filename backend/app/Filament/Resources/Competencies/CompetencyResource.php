<?php

namespace App\Filament\Resources\Competencies;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Competencies\Pages\CreateCompetency;
use App\Filament\Resources\Competencies\Pages\EditCompetency;
use App\Filament\Resources\Competencies\Pages\ListCompetencies;
use App\Filament\Resources\Competencies\RelationManagers\PackagesRelationManager;
use App\Models\Competency;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Master kompetensi (Roadmap 5F, PRD R5 §13 "Competency", §20.16). Dipakai untuk target per posisi organisasi,
 * kriteria rubrik penilaian (bukti assessment), dan program yang membangun kompetensi (bukti training + rekomendasi).
 */
class CompetencyResource extends Resource
{
    protected static ?string $model = Competency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsProgram;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Master kompetensi';

    protected static ?string $modelLabel = 'Kompetensi';

    protected static ?string $pluralModelLabel = 'Master kompetensi';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('training.manage') || $u?->hasPermission('monitoring.manage') || $u?->isSuperAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kompetensi')->columns(2)->columnSpanFull()->components([
                TextInput::make('code')->label('Kode')->required()->maxLength(40)->placeholder('PLC')
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) $state)))
                    ->helperText('Huruf besar, dipakai di portal perusahaan & API.'),
                TextInput::make('name')->label('Nama')->required()->maxLength(255)->placeholder('Pemrograman PLC'),
                TextInput::make('category')->label('Kategori')->maxLength(80)->placeholder('Otomasi industri'),
                TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
                Textarea::make('description')->label('Deskripsi / indikator')->rows(3)->columnSpanFull(),
                Toggle::make('is_active')->label('Aktif')->default(true)
                    ->helperText('Kompetensi nonaktif tidak bisa dipilih sebagai target dan tidak dihitung di matriks gap.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable()->badge()->color('gray'),
                TextColumn::make('name')->label('Nama')->searchable()->sortable()->description(fn (Competency $record) => $record->category),
                TextColumn::make('packages_count')->label('Program')->counts('packages'),
                TextColumn::make('criteria_count')->label('Kriteria rubrik')->counts('criteria'),
                TextColumn::make('targets_count')->label('Target organisasi')->counts('targets'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Aktif')])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [PackagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompetencies::route('/'),
            'create' => CreateCompetency::route('/create'),
            'edit' => EditCompetency::route('/{record}/edit'),
        ];
    }
}
