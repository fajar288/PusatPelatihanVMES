<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Filament\Support\Layout;
use App\Models\Competency;
use App\Models\CompetencyTarget;
use App\Services\CompetencyGapService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Target kompetensi per posisi organisasi (Roadmap 5F, §20.16) - sama dengan yang dikelola Corporate Owner/HR di portal.
 * Posisi dicocokkan dengan jabatan employment aktif (huruf besar/kecil & spasi diabaikan). Semua perubahan lewat
 * CompetencyGapService agar tercatat di audit_logs.
 */
class CompetencyTargetsRelationManager extends RelationManager
{
    protected static string $relationship = 'competencyTargets';

    protected static ?string $title = 'Target kompetensi per posisi';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(Layout::cols(3))->components([
            TextInput::make('position')->label('Posisi')->required()->maxLength(150)
                ->datalist(fn () => $this->getOwnerRecord()->employments()->whereNotNull('position')->distinct()->orderBy('position')->pluck('position')->all())
                ->helperText('Sama dengan jabatan di data karyawan.'),
            Select::make('competency_id')->label('Kompetensi')->required()->native(false)->searchable()
                ->options(fn () => Competency::query()->active()->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn (Competency $c) => [$c->id => $c->label()])->all()),
            TextInput::make('required_level')->label('Level minimal (1-100)')->numeric()->minValue(1)->maxValue(100)->required()->default(75),
        ]);
    }

    public function table(Table $table): Table
    {
        $save = fn (array $data, ?CompetencyTarget $record = null): Model => app(CompetencyGapService::class)->setTarget(
            $this->getOwnerRecord(), (string) $data['position'], Competency::query()->findOrFail($data['competency_id']), (int) $data['required_level'], auth()->user(),
        );

        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('position')->label('Posisi')->searchable()->sortable(),
                TextColumn::make('competency.name')->label('Kompetensi')->description(fn (CompetencyTarget $record) => $record->competency?->code),
                TextColumn::make('required_level')->label('Level minimal')->suffix(' / 100')->sortable(),
                TextColumn::make('updated_at')->label('Diubah')->since()->toggleable(),
            ])
            ->headerActions([CreateAction::make()->label('Tambah target')->using(fn (array $data) => $save($data))])
            ->recordActions([
                EditAction::make()->using(function (CompetencyTarget $record, array $data) use ($save): Model {
                    if (CompetencyTarget::key($data['position']) !== $record->position_key || (int) $data['competency_id'] !== $record->competency_id) {
                        app(CompetencyGapService::class)->deleteTarget($record, auth()->user());
                    }

                    return $save($data);
                }),
                DeleteAction::make()->using(fn (CompetencyTarget $record) => app(CompetencyGapService::class)->deleteTarget($record, auth()->user())),
            ]);
    }
}
