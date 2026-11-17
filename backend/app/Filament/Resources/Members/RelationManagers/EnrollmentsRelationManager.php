<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Filament\Resources\TrainingSchedules\TrainingScheduleResource;
use App\Models\Enrollment;
use App\Services\LmsService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Enrollment peserta dengan kehadiran, progres LMS, dan nilai akhir; tautan ke kelas. */
class EnrollmentsRelationManager extends RelationManager
{
    public const STATUS_LABELS = ['enrolled' => 'Terdaftar', 'attending' => 'Mengikuti', 'completed' => 'Lulus', 'failed' => 'Tidak lulus', 'dropped' => 'Mundur'];

    protected static string $relationship = 'enrollments';

    protected static ?string $title = 'Enrollment & progres';

    public function isReadOnly(): bool
    {
        return true;
    }

    /** Rata-rata progres semua course LMS kelas ini (null bila kelas tidak punya course). */
    public static function lmsPercent(Enrollment $enrollment): ?int
    {
        $courses = app(LmsService::class)->coursesForEnrollment($enrollment);

        return $courses->isEmpty() ? null : (int) round($courses->avg('percent'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['schedule.package', 'assessment']))
            ->columns([
                TextColumn::make('schedule.code')->label('Kelas'),
                TextColumn::make('program')->label('Program')->state(fn (Enrollment $record) => $record->schedule?->title ?? $record->schedule?->package?->title ?? '-')->wrap(),
                TextColumn::make('schedule.start_date')->label('Mulai')->date('d M Y'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (?string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        'completed' => 'success', 'failed' => 'danger', 'dropped' => 'gray', default => 'info',
                    }),
                TextColumn::make('attendance')->label('Kehadiran')->state(fn (Enrollment $record) => $record->attendancePercent())->suffix('%')->placeholder('-')->alignCenter(),
                TextColumn::make('lms')->label('Progres LMS')->state(fn (Enrollment $record) => self::lmsPercent($record))->suffix('%')->placeholder('-')->alignCenter(),
                TextColumn::make('assessment.total_percent')->label('Nilai akhir')->numeric(1)->placeholder('-')->alignCenter(),
                TextColumn::make('completed_at')->label('Selesai')->date('d M Y')->placeholder('-'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('open')->label('Buka kelas')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn (Enrollment $record) => $record->schedule !== null && TrainingScheduleResource::canViewAny())
                    ->url(fn (Enrollment $record) => TrainingScheduleResource::getUrl('edit', ['record' => $record->schedule])),
            ]);
    }
}
