<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Models\AssignmentSubmission;
use App\Services\LmsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Pengumpulan tugas peserta (lesson bertipe tugas): lihat berkas, beri nilai/umpan balik atau kembalikan. */
class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    protected static ?string $title = 'Pengumpulan tugas';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'assignment';
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['enrollment.schedule', 'grader']))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('enrollment.participant_name')->label('Peserta')->searchable()->description(fn ($record) => $record->enrollment?->schedule?->code),
                TextColumn::make('submitted_at')->label('Dikumpulkan')->dateTime('d M Y H:i'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => AssignmentSubmission::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'graded' => 'success', 'returned' => 'warning', default => 'gray'
                    }),
                TextColumn::make('score')->label('Nilai')->placeholder('-'),
                TextColumn::make('grader.name')->label('Dinilai oleh')->placeholder('-'),
                TextColumn::make('file_name')->label('Berkas')->placeholder('-'),
            ])
            ->filters([SelectFilter::make('status')->options(AssignmentSubmission::STATUSES)])
            ->recordActions([
                Action::make('download')->label('Unduh')->icon('heroicon-o-arrow-down-tray')->visible(fn ($record) => (bool) $record->file_path)
                    ->action(fn ($record) => Storage::disk(config('filesystems.default'))->download($record->file_path, $record->file_name ?: basename($record->file_path))),
                Action::make('grade')->label('Nilai')->icon('heroicon-o-pencil-square')->modalHeading('Penilaian tugas')
                    ->schema(fn ($record) => [
                        Textarea::make('text')->label('Jawaban teks')->default($record->text)->disabled()->rows(4)->visible((bool) $record->text),
                        Select::make('status')->label('Keputusan')->options(['graded' => 'Dinilai', 'returned' => 'Dikembalikan untuk revisi'])->default('graded')->required()->native(false),
                        TextInput::make('score')->label('Nilai (maks. '.($record->lesson->config()['max_score'] ?? 100).')')->numeric()->default($record->score)->minValue(0),
                        Textarea::make('feedback')->label('Umpan balik')->default($record->feedback)->rows(3),
                    ])
                    ->action(function (array $data, $record): void {
                        app(LmsService::class)->grade($record, isset($data['score']) && $data['score'] !== '' ? (float) $data['score'] : null, $data['feedback'] ?? null, $data['status'], auth()->user());
                    }),
            ]);
    }
}
