<?php

namespace App\Filament\Resources\Lessons;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\Lessons\RelationManagers\ResourcesRelationManager;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\Filament\Resources\Lessons\Schemas\LessonForm;
use App\Filament\Resources\Lessons\Tables\LessonsTable;
use App\Models\Lesson;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** LMS — Lesson (materi teks/dokumen/video/tautan/kuis/tugas) dalam modul course. */
class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Lms;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Lesson';

    protected static ?string $modelLabel = 'Lesson';

    protected static ?string $pluralModelLabel = 'Lesson';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('lms.manage') || $u?->isAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return LessonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LessonsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ResourcesRelationManager::class, QuestionsRelationManager::class, SubmissionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLessons::route('/'),
            'create' => CreateLesson::route('/create'),
            'edit' => EditLesson::route('/{record}/edit'),
        ];
    }
}
