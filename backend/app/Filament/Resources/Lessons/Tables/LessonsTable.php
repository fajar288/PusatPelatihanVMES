<?php

namespace App\Filament\Resources\Lessons\Tables;

use App\Models\CourseModule;
use App\Models\Lesson;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LessonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('module.course'))
            ->columns([
                TextColumn::make('module.course.title')->label('Course')->sortable()->searchable(),
                TextColumn::make('module.title')->label('Modul')->sortable(),
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('title')->label('Lesson')->searchable()->wrap(),
                TextColumn::make('type')->label('Tipe')->badge()->formatStateUsing(fn ($state) => Lesson::TYPES[$state] ?? $state),
                TextColumn::make('duration_minutes')->label('Menit'),
                IconColumn::make('is_required')->label('Wajib')->boolean(),
                IconColumn::make('is_published')->label('Terbit')->boolean(),
            ])
            ->filters([
                SelectFilter::make('course')->label('Course')->relationship('module.course', 'title')->searchable()->preload(),
                SelectFilter::make('module_id')->label('Modul')->options(fn () => CourseModule::query()->with('course')->orderBy('course_id')->orderBy('sort_order')->get()->mapWithKeys(fn ($m) => [$m->id => ($m->course?->title ?? '').' › '.$m->title])),
                SelectFilter::make('type')->label('Tipe')->options(Lesson::TYPES),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
