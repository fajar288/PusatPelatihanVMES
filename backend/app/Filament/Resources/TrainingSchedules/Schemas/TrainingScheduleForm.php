<?php

namespace App\Filament\Resources\TrainingSchedules\Schemas;

use App\Filament\Support\Fields;
use App\Models\TrainingSchedule;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TrainingScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        $statusLabels = [
            'draft' => 'Draft', 'registration_open' => 'Pendaftaran dibuka', 'registration_closed' => 'Pendaftaran ditutup',
            'confirmed' => 'Terkonfirmasi', 'ongoing' => 'Berlangsung', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan',
        ];

        return $schema
            ->components([
                Section::make('Jadwal / kelas')->columns(2)->components([
                    Select::make('package_id')->label('Paket training')->relationship('package', 'title')->searchable()->preload()->required(),
                    TextInput::make('code')->label('Kode kelas')->required()->maxLength(40)->unique('training_schedules', 'code', ignoreRecord: true)->placeholder('CCNA-2611'),
                    TextInput::make('title')->label('Judul (override, opsional)')->maxLength(255),
                    TextInput::make('trainer_name')->label('Trainer')->maxLength(255),
                    DatePicker::make('start_date')->label('Tanggal mulai')->required()->native(false),
                    DatePicker::make('end_date')->label('Tanggal selesai')->required()->native(false)->afterOrEqual('start_date'),
                    TimePicker::make('start_time')->label('Jam mulai')->seconds(false),
                    TimePicker::make('end_time')->label('Jam selesai')->seconds(false),
                    Fields::mode()->label('Metode'),
                    TextInput::make('city')->label('Kota')->maxLength(80),
                    TextInput::make('location')->label('Lokasi / tautan kelas')->maxLength(255)->columnSpanFull(),
                ]),
                Section::make('Kuota, harga & status')->columns(3)->components([
                    TextInput::make('quota')->label('Kuota')->numeric()->minValue(0),
                    TextInput::make('registered_count')->label('Terdaftar')->numeric()->minValue(0)->default(0),
                    TextInput::make('price')->label('Harga (override)')->numeric()->minValue(0)->prefix('Rp')->helperText('Kosong = harga paket'),
                    Select::make('status')->options(array_intersect_key($statusLabels, array_flip(TrainingSchedule::STATUSES)))->default('draft')->required()->native(false),
                    DatePicker::make('registration_deadline')->label('Batas pendaftaran')->native(false),
                    DateTimePicker::make('published_at')->label('Tanggal terbit')->seconds(false)->native(false),
                    Textarea::make('notes')->label('Catatan / alasan perubahan')->rows(3)->columnSpanFull(),
                ]),
            ]);
    }
}
