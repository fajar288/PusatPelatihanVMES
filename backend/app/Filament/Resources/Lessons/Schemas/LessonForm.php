<?php

namespace App\Filament\Resources\Lessons\Schemas;

use App\Filament\Support\Fields;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class LessonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Penempatan')->columns(3)->components([
                Select::make('course_id')->label('Course')->options(fn () => Course::query()->orderBy('title')->pluck('title', 'id'))->searchable()->required()->live()->dehydrated(false)
                    ->afterStateHydrated(fn (Set $set, ?Lesson $record) => $record ? $set('course_id', $record->module?->course_id) : null)
                    ->afterStateUpdated(fn (Set $set) => $set('module_id', null)),
                Select::make('module_id')->label('Modul')->required()->searchable()
                    ->options(fn (Get $get) => CourseModule::query()->where('course_id', $get('course_id') ?? 0)->orderBy('sort_order')->pluck('title', 'id')),
                TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
            ]),
            Section::make('Lesson')->columns(3)->components([
                TextInput::make('title')->label('Judul')->required()->maxLength(255)->columnSpan(2),
                Select::make('type')->label('Tipe')->options(Lesson::TYPES)->default('text')->required()->native(false)->live(),
                RichEditor::make('body')->label(fn (Get $get) => $get('type') === 'assignment' ? 'Instruksi tugas' : 'Materi / deskripsi')->columnSpanFull(),
                TextInput::make('duration_minutes')->label('Durasi (menit)')->numeric()->minValue(0),
                Toggle::make('is_required')->label('Wajib (dihitung progres)')->default(true)->inline(false),
                Toggle::make('is_published')->label('Terbit')->default(true)->inline(false),
                DatePicker::make('available_from')->label('Tersedia mulai')->native(false)->helperText('Kosong = segera.'),
            ]),
            Section::make('Video')->columns(3)->visible(fn (Get $get) => $get('type') === 'video')->components([
                TextInput::make('settings.video_url')->label('URL video')->url()->columnSpan(2)->helperText('YouTube, Vimeo, atau URL file MP4 (streaming).'),
                TextInput::make('settings.duration_seconds')->label('Durasi (detik)')->numeric(),
                Fields::image('settings.thumbnail', 'lms/video', 1280)->label('Thumbnail')->columnSpan(2),
                TextInput::make('settings.min_watch_pct')->label('Minimal ditonton (%) untuk selesai')->numeric()->default(80)->minValue(1)->maxValue(100),
            ]),
            Section::make('Tautan')->visible(fn (Get $get) => $get('type') === 'link')->components([
                TextInput::make('settings.url')->label('URL')->url()->required(),
            ]),
            Section::make('Kuis')->columns(3)->visible(fn (Get $get) => $get('type') === 'quiz')->description('Soal ditambahkan di tab "Soal" setelah lesson disimpan.')->components([
                Select::make('settings.kind')->label('Jenis')->options(Lesson::QUIZ_KINDS)->default('quiz')->native(false),
                TextInput::make('settings.passing_score')->label('Nilai lulus (%)')->numeric()->default(70)->minValue(0)->maxValue(100),
                TextInput::make('settings.max_attempts')->label('Maks. percobaan')->numeric()->default(3)->minValue(0)->helperText('0 = tak terbatas'),
                TextInput::make('settings.time_limit_minutes')->label('Batas waktu (menit)')->numeric()->minValue(0)->helperText('Kosong = tanpa batas'),
                Toggle::make('settings.shuffle_questions')->label('Acak soal')->inline(false),
                Toggle::make('settings.show_answers')->label('Tampilkan kunci setelah submit')->default(true)->inline(false),
            ]),
            Section::make('Tugas')->columns(4)->visible(fn (Get $get) => $get('type') === 'assignment')->components([
                DateTimePicker::make('settings.due_at')->label('Batas waktu')->native(false)->seconds(false),
                TextInput::make('settings.max_score')->label('Nilai maksimal')->numeric()->default(100)->minValue(1),
                Toggle::make('settings.allow_text')->label('Jawaban teks')->default(true)->inline(false),
                Toggle::make('settings.allow_file')->label('Unggah berkas')->default(true)->inline(false),
            ]),
        ]);
    }
}
