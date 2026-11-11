<?php

namespace App\Filament\Resources\CompetencyAssessments;

use App\Filament\Resources\CompetencyAssessments\Pages\EditCompetencyAssessment;
use App\Filament\Resources\CompetencyAssessments\Pages\ListCompetencyAssessments;
use App\Models\CompetencyAssessment;
use App\Models\TrainingSchedule;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Monitoring — penilaian kompetensi individual per peserta berdasarkan rubrik program (PRD §5.9). */
class CompetencyAssessmentResource extends Resource
{
    protected static ?string $model = CompetencyAssessment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Penilaian kompetensi';

    protected static ?string $modelLabel = 'Penilaian kompetensi';

    protected static ?string $pluralModelLabel = 'Penilaian kompetensi';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('monitoring.manage') || $u?->isAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Peserta')->columns(3)->components([
                Placeholder::make('participant')->label('Peserta')->content(fn (?CompetencyAssessment $record) => $record?->enrollment?->participant_name ?? '-'),
                Placeholder::make('class')->label('Kelas')->content(fn (?CompetencyAssessment $record) => ($record?->enrollment?->schedule?->code ?? '-').' - '.($record?->enrollment?->schedule?->title ?? $record?->enrollment?->schedule?->package?->title ?? '')),
                Placeholder::make('rubric_name')->label('Rubrik')->content(fn (?CompetencyAssessment $record) => ($record?->rubric?->name ?? '-').' (ambang '.($record?->rubric?->competent_threshold ?? 70).'%)'),
                Placeholder::make('status_info')->label('Status')->columnSpanFull()
                    ->content(fn (?CompetencyAssessment $record) => new HtmlString($record?->is_locked
                        ? '<span class="text-warning-600 font-semibold">Tervalidasi & terkunci</span> oleh '.e($record->validator?->name ?? '-').' pada '.($record->validated_at?->format('d M Y H:i') ?? '-').'. Total: <b>'.($record->total_percent !== null ? (float) $record->total_percent.'%' : '-').'</b> ('.($record->is_competent ? 'Kompeten' : 'Belum kompeten').'). Perubahan akan tercatat sebagai pasca-validasi di audit log.'
                        : 'Draf. Total saat ini: <b>'.($record?->total_percent !== null ? (float) $record->total_percent.'%' : '-').'</b>')),
            ]),
            Section::make('Skor per kriteria')->components([
                Repeater::make('scores')->relationship()->label('')->addable(false)->deletable(false)->reorderable(false)->columns(6)
                    ->schema([
                        Select::make('criterion_id')->label('Kriteria')->disabled()->dehydrated()->columnSpan(3)
                            ->options(fn ($livewire) => $livewire->getRecord()?->rubric?->criteria->mapWithKeys(fn ($c) => [$c->id => $c->name.' (maks. '.$c->max_score.', bobot '.(float) $c->weight.')']) ?? []),
                        TextInput::make('score')->label('Skor')->numeric()->minValue(0)->maxValue(100),
                        TextInput::make('note')->label('Catatan')->maxLength(255)->columnSpan(2),
                    ]),
            ]),
            Section::make('Catatan trainer')->columns(2)->components([
                Textarea::make('summary')->label('Ringkasan / umpan balik')->rows(3),
                Textarea::make('recommendation')->label('Rekomendasi')->rows(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['enrollment.schedule', 'rubric', 'trainer', 'assessor']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('enrollment.participant_name')->label('Peserta')->searchable(),
                TextColumn::make('enrollment.schedule.code')->label('Kelas'),
                TextColumn::make('rubric.name')->label('Rubrik')->limit(30),
                TextColumn::make('total_percent')->label('Total')->suffix('%')->placeholder('-')->alignCenter(),
                IconColumn::make('is_competent')->label('Kompeten')->boolean()->placeholder('-'),
                TextColumn::make('trainer.name')->label('Penilai')->state(fn (CompetencyAssessment $a) => $a->trainer?->name ?? $a->assessor?->name)->placeholder('-'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => CompetencyAssessment::STATUSES[$state] ?? $state)->color(fn ($state) => $state === 'validated' ? 'success' : 'gray'),
                TextColumn::make('assessed_at')->label('Dinilai')->dateTime('d M Y H:i')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('schedule')->label('Kelas')->options(fn () => TrainingSchedule::query()->orderByDesc('start_date')->limit(300)->pluck('code', 'id'))
                    ->query(fn ($query, array $data) => $data['value'] ? $query->whereHas('enrollment', fn ($e) => $e->where('schedule_id', $data['value'])) : $query),
                SelectFilter::make('status')->options(CompetencyAssessment::STATUSES),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompetencyAssessments::route('/'),
            'edit' => EditCompetencyAssessment::route('/{record}/edit'),
        ];
    }
}
