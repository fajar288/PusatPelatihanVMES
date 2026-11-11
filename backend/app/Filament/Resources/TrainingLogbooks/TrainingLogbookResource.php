<?php

namespace App\Filament\Resources\TrainingLogbooks;

use App\Filament\NavigationGroup;
use App\Filament\Resources\TrainingLogbooks\Pages\CreateTrainingLogbook;
use App\Filament\Resources\TrainingLogbooks\Pages\EditTrainingLogbook;
use App\Filament\Resources\TrainingLogbooks\Pages\ListTrainingLogbooks;
use App\Filament\Resources\TrainingLogbooks\RelationManagers\EntriesRelationManager;
use App\Models\ClassSession;
use App\Models\Trainer;
use App\Models\TrainingLogbook;
use App\Models\TrainingSchedule;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Monitoring — logbook per pertemuan (PRD §5.9). Dokumentasi diunggah trainer lewat portal; admin memvalidasi/membuka kunci. */
class TrainingLogbookResource extends Resource
{
    protected static ?string $model = TrainingLogbook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsDelivery;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Logbook';

    protected static ?string $modelLabel = 'Logbook';

    protected static ?string $pluralModelLabel = 'Logbook';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('monitoring.manage') || $u?->isAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pertemuan')->columns(3)->components([
                Select::make('schedule_id')->label('Kelas')->required()->searchable()->live()
                    ->options(fn () => TrainingSchedule::query()->with('package')->orderByDesc('start_date')->limit(300)->get()->mapWithKeys(fn ($s) => [$s->id => $s->code.' - '.($s->title ?? $s->package?->title)])),
                Select::make('session_id')->label('Sesi')->searchable()
                    ->options(fn (Get $get) => ClassSession::query()->where('schedule_id', $get('schedule_id') ?? 0)->orderBy('session_no')->get()->mapWithKeys(fn ($s) => [$s->id => $s->label()])),
                Select::make('trainer_id')->label('Trainer')->searchable()->options(fn () => Trainer::query()->active()->pluck('name', 'id')),
                DatePicker::make('date')->label('Tanggal')->required()->native(false),
                TextInput::make('topic')->label('Topik')->required()->maxLength(255)->columnSpan(2),
                Placeholder::make('status_info')->label('Status')->columnSpanFull()
                    ->content(fn (?TrainingLogbook $record) => new HtmlString($record?->is_locked
                        ? '<span class="text-warning-600 font-semibold">Tervalidasi & terkunci</span> oleh '.e($record->validator?->name ?? '-').' pada '.($record->validated_at?->format('d M Y H:i') ?? '-').'. Perubahan akan tercatat sebagai perubahan pasca-validasi di audit log.'
                        : 'Draf (belum divalidasi).')),
            ]),
            Section::make('Isi logbook')->columns(2)->components([
                Textarea::make('activities')->label('Aktivitas pembelajaran')->rows(3),
                TagsInput::make('competencies')->label('Kompetensi yang dilatih')->placeholder('Tambah kompetensi, Enter'),
                Textarea::make('practice')->label('Praktik yang dilakukan')->rows(3),
                Textarea::make('observations')->label('Observasi trainer')->rows(3),
                Textarea::make('obstacles')->label('Kendala')->rows(2),
                Textarea::make('notes')->label('Catatan trainer')->rows(2),
                Placeholder::make('documentation_info')->label('Dokumentasi')->columnSpanFull()
                    ->content(fn (?TrainingLogbook $record) => $record && $record->documentation ? collect($record->documentation)->pluck('name')->implode(', ') : 'Belum ada (diunggah trainer lewat portal).'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['schedule', 'session', 'trainer'])->withCount('entries'))
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('schedule.code')->label('Kelas')->searchable()->sortable(),
                TextColumn::make('date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('session.session_no')->label('Sesi')->alignCenter()->placeholder('-'),
                TextColumn::make('topic')->label('Topik')->searchable()->limit(50),
                TextColumn::make('trainer.name')->label('Trainer')->placeholder('-'),
                TextColumn::make('entries_count')->label('Catatan peserta')->alignCenter(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => TrainingLogbook::STATUSES[$state] ?? $state)->color(fn ($state) => $state === 'validated' ? 'success' : 'gray'),
                IconColumn::make('is_locked')->label('Kunci')->boolean()->trueIcon('heroicon-o-lock-closed')->falseIcon('heroicon-o-lock-open')->trueColor('warning')->falseColor('gray'),
            ])
            ->filters([
                SelectFilter::make('schedule_id')->label('Kelas')->options(fn () => TrainingSchedule::query()->orderByDesc('start_date')->limit(300)->pluck('code', 'id')),
                SelectFilter::make('status')->options(TrainingLogbook::STATUSES),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingLogbooks::route('/'),
            'create' => CreateTrainingLogbook::route('/create'),
            'edit' => EditTrainingLogbook::route('/{record}/edit'),
        ];
    }
}
