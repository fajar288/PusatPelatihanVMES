<?php

namespace App\Filament\Resources\Attendances\Schemas;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Enrollment;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** Kehadiran per sesi (PRD §5.5): pilih sesi → peserta kelas tersebut → status. */
class AttendanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('session_id')->label('Sesi')->required()->live()->searchable()
                ->options(fn () => ClassSession::query()->with('schedule')->orderByDesc('date')->limit(200)->get()->mapWithKeys(fn ($s) => [$s->id => ($s->schedule?->code ?? '').' · '.$s->label()])),
            Select::make('enrollment_id')->label('Peserta')->required()->searchable()
                ->options(function (Get $get) {
                    $session = ClassSession::query()->find($get('session_id'));

                    return $session ? Enrollment::query()->where('schedule_id', $session->schedule_id)->orderBy('participant_name')->pluck('participant_name', 'id') : [];
                }),
            Select::make('status')->options(Attendance::LABELS)->default('present')->required()->native(false),
            TextInput::make('note')->label('Catatan')->maxLength(255),
        ]);
    }
}
