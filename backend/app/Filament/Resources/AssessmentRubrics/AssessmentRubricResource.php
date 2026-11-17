<?php

namespace App\Filament\Resources\AssessmentRubrics;

use App\Filament\NavigationGroup;
use App\Filament\Resources\AssessmentRubrics\Pages\CreateAssessmentRubric;
use App\Filament\Resources\AssessmentRubrics\Pages\EditAssessmentRubric;
use App\Filament\Resources\AssessmentRubrics\Pages\ListAssessmentRubrics;
use App\Filament\Support\Layout;
use App\Models\AssessmentRubric;
use App\Models\Competency;
use App\Models\RubricCriterion;
use App\Models\TrainingPackage;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Monitoring - rubrik penilaian kompetensi per program (PRD §5.9): kriteria, bobot, skala & deskriptor level. */
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
        // Atas-bawah: section Rubrik lalu Kriteria, keduanya selebar halaman. Grid responsif:
        // satu kolom di layar sempit, dua kolom mulai md, susunan per baris penuh di xl.
        return $schema->columns(1)->components([
            Section::make('Rubrik')->columnSpanFull()->columns(Layout::cols(4))->components([
                Select::make('package_id')->label('Program / paket')->required()->searchable()->columnSpan(Layout::span(2))
                    ->options(fn () => TrainingPackage::query()->orderBy('title')->pluck('title', 'id')),
                TextInput::make('name')->label('Nama rubrik')->required()->maxLength(255),
                TextInput::make('competent_threshold')->label('Ambang kompeten (%)')->numeric()->minValue(0)->maxValue(100)->default(70)->required(),
                Textarea::make('description')->label('Keterangan')->rows(2)->columnSpanFull(),
                Toggle::make('is_active')->label('Aktif (dipakai penilaian baru)')->default(true)->inline(false)->columnSpanFull(),
            ]),
            Section::make('Kriteria penilaian')->columnSpanFull()->description('Sesuaikan dengan kompetensi program. Nilai akhir = rata-rata tertimbang (skor ÷ skor maks × bobot) × 100.')->components([
                Repeater::make('criteria')->relationship()->hiddenLabel()->orderColumn('sort_order')->reorderable()->columns(Layout::cols(12))
                    ->defaultItems(0)->addActionLabel('Tambah kriteria')->itemLabel(fn (array $state) => $state['name'] ?? null)->collapsible()
                    ->schema([
                        // Baris 1: Aspek | Nama kriteria (lebih lebar) | Bobot | Skor maks.
                        Select::make('code')->label('Aspek')->options(RubricCriterion::CODES)->default('custom')->native(false)->columnSpan(Layout::span(3, 1)),
                        TextInput::make('name')->label('Nama kriteria')->required()->maxLength(255)->columnSpan(Layout::span(5, 1)),
                        TextInput::make('weight')->label('Bobot')->numeric()->minValue(0)->default(1)->required()->columnSpan(Layout::span(2, 1)),
                        TextInput::make('max_score')->label('Skor maks.')->numeric()->minValue(1)->maxValue(100)->default(4)->required()->columnSpan(Layout::span(2, 1)),
                        // Baris 2: Unit kompetensi | Master kompetensi
                        TextInput::make('competency')->label('Unit kompetensi')->maxLength(255)->columnSpan(Layout::span(6, 1)),
                        Select::make('competency_id')->label('Master kompetensi')->columnSpan(Layout::span(6, 1))->native(false)->searchable()->nullable()
                            ->options(fn () => Competency::query()->active()->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn (Competency $c) => [$c->id => $c->label()])->all())
                            ->helperText('Skor kriteria ini menjadi bukti kompetensi untuk matriks gap perusahaan.'),
                        // Baris 3 dan 4: Deskripsi, lalu deskriptor level (satu level per baris)
                        Textarea::make('description')->label('Deskripsi')->rows(2)->columnSpanFull(),
                        Repeater::make('levels')->label('Deskriptor level (opsional)')->columnSpanFull()->defaultItems(0)->addActionLabel('Tambah level')->reorderable(false)
                            ->default(AssessmentRubric::DEFAULT_LEVELS)
                            // Mode tabel: satu level per baris (Skor | Label | Deskripsi); di bawah 1024 px tiap level menumpuk.
                            ->table([
                                TableColumn::make('Skor')->markAsRequired()->width('7rem'),
                                TableColumn::make('Label')->markAsRequired()->width('16rem'),
                                TableColumn::make('Deskripsi'),
                            ])
                            ->schema([
                                TextInput::make('score')->label('Skor')->numeric()->required(),
                                TextInput::make('label')->label('Label')->required()->maxLength(60),
                                TextInput::make('description')->label('Deskripsi')->maxLength(255),
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
