<?php

namespace App\Filament\Resources\TrainingSchedules\RelationManagers;

use App\Models\Enrollment;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Peserta resmi kelas (enrollment) — otomatis dari pendaftaran terkonfirmasi, bisa ditambah manual. */
class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $title = 'Peserta';

    private const STATUS = ['enrolled' => 'Terdaftar', 'attending' => 'Mengikuti', 'completed' => 'Selesai', 'failed' => 'Tidak lulus', 'dropped' => 'Mengundurkan diri'];

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('participant_name')->label('Nama peserta')->required()->maxLength(255),
            TextInput::make('participant_email')->label('Email')->email()->maxLength(255),
            Select::make('member_id')->label('Member')->relationship('member', 'member_number')->searchable(['member_number'])->preload()
                ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->member_number} — ".($record->user?->name ?? '')),
            Select::make('status')->options(self::STATUS)->default('enrolled')->required()->native(false),
            TextInput::make('final_score')->label('Nilai akhir')->numeric()->minValue(0)->maxValue(100),
            Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('participant_name')
            ->columns([
                TextColumn::make('participant_name')->label('Peserta')->searchable()->description(fn (Enrollment $e) => $e->participant_email),
                TextColumn::make('member.member_number')->label('No. member'),
                TextColumn::make('registration.registration_number')->label('Registrasi'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => self::STATUS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'completed' => 'success', 'failed', 'dropped' => 'danger', 'attending' => 'warning', default => 'gray'
                    }),
                TextColumn::make('attendance')->label('Kehadiran')->state(fn (Enrollment $e) => $e->attendancePercent() === null ? '-' : $e->attendancePercent().'%'),
                TextColumn::make('final_score')->label('Nilai'),
                TextColumn::make('enrolled_at')->label('Masuk')->dateTime('d M Y'),
            ])
            ->filters([SelectFilter::make('status')->options(self::STATUS)])
            ->headerActions([CreateAction::make()->label('Tambah peserta manual')->mutateDataUsing(fn (array $data) => $data + ['enrolled_at' => now()])])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
