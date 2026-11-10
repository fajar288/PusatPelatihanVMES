<?php

namespace App\Filament\Resources\Waitlists\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WaitlistForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('schedule_id')
                    ->relationship('schedule', 'title')
                    ->required(),
                Select::make('member_id')
                    ->relationship('member', 'id'),
                Select::make('registration_id')
                    ->relationship('registration', 'name'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('participants_count')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('status')
                    ->required()
                    ->default('waiting'),
                DateTimePicker::make('offered_at'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
