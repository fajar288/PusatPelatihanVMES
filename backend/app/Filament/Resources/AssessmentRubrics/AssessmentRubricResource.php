<?php

namespace App\Filament\Resources\AssessmentRubrics;

use App\Filament\NavigationGroup;
use App\Filament\Resources\AssessmentRubrics\Pages\CreateAssessmentRubric;
use App\Filament\Resources\AssessmentRubrics\Pages\EditAssessmentRubric;
use App\Filament\Resources\AssessmentRubrics\Pages\ListAssessmentRubrics;
use App\Models\AssessmentRubric;
use App\Models\RubricCriterion;
use App\Models\TrainingPackage;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Monitoring — rubrik penilaian kompetensi per program (PRD §5.9): kriteria, bobot, skala & deskriptor level. */
class AssessmentRubricResource extends Resource
{
    protected static ?string $model = AssessmentRubric::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsDelivery;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Rubrik penilaian';

    protected static ?string $modelLabel = 'Rubrik penilaian';

    protected static ?string $pluralModelLabel = 'Rubrik penilaian';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('monitoring.manage') || $u?->isAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rubrik')->columns(3)->components([
                Select::make('package_id')->label('Program / paket')->required()->searchable()
                    ->options(fn () => TrainingPackage::query()->orderBy('title')->pluck('title', 'id')),
                TextInput::make('name')->label('Nama rubrik')->required()->maxLength(255),
                TextInput::make('competent_threshold')->label('Ambang kompeten (%)')->numeric()->minValue(0)->maxValue(100)->default(70)->required(),
                Textarea::make('description')->label('Keterangan')->rows(2)->columnSpan(2),
                Toggle::make('is_active')->label('Aktif (dipakai penilaian baru)')->default(true)->inline(false),
            ]),
            Section::make('Kriteria penilaian')->description('Sesuaikan dengan kompetensi program. Nilai akhir = rata-rata tertimbang (skor ÷ skor maks × bobot) × 100.')->components([
                Repeater::make('criteria')->relationship()->label('')->orderColumn('sort_order')->reorderable()->columns(6)
                    ->defaultItems(0)->addActionLabel('Tambah kriteria')->itemLabel(fn (array $state) => $state['name'] ?? null)->collapsible()
                    ->schema([
                        Select::make('code')->label('Aspek')->options(RubricCriterion::CODES)->default('custom')->native(false),
                        TextInput::make('name')->label('Nama kriteria')->required()->maxLength(255)->columnSpan(2),
                        TextInput::make('competency')->label('Unit kompetensi')->maxLength(255),
                        TextInput::make('weight')->label('Bobot')->numeric()->minValue(0)->default(1)->required(),
                        TextInput::make('max_score')->label('Skor maks.')->numeric()->minValue(1)->maxValue(100)->default(4)->required(),
                        Textarea::make('description')->label('Deskripsi')->rows(2)->columnSpanFull(),
                        Repeater::make('levels')->label('Deskriptor level (opsional)')->columns(4)->columnSpanFull()->defaultItems(0)->addActionLabel('Tambah level')->reorderable(false)
                            ->default(AssessmentRubric::DEFAULT_LEVELS)
                            ->schema([
                                TextInput::make('score')->label('Skor')->numeric()->required(),
                                TextInput::make('label')->label('Label')->required()->maxLength(60),
                                TextInput::make('description')->label('Deskripsi')->maxLength(255)->columnSpan(2),
                            ]),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('package.title')->label('Program')->searchable()->sortable(),
                TextColumn::make('name')->label('Rubrik')->searchable(),
                TextColumn::make('criteria_count')->label('Kriteria')->counts('criteria')->alignCenter(),
                TextColumn::make('competent_threshold')->label('Ambang')->suffix('%')->alignCenter(),
                TextColumn::make('assessments_count')->label('Dipakai')->counts('assessments')->alignCenter(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([SelectFilter::make('package_id')->label('Program')->options(fn () => TrainingPackage::query()->orderBy('title')->pluck('title', 'id'))])
            ->recordActions([EditAction::make(), DeleteAction::make()->visible(fn (AssessmentRubric $r) => $r->assessments()->doesntExist())]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssessmentRubrics::route('/'),
            'create' => CreateAssessmentRubric::route('/create'),
            'edit' => EditAssessmentRubric::route('/{record}/edit'),
        ];
    }
}
