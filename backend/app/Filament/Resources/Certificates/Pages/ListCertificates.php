<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Certificate;
use App\Models\TrainingSchedule;
use App\Services\CertificateService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCertificates extends ListRecords
{
    protected static string $resource = CertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateForClass')->label('Buat draf untuk kelas')->icon('heroicon-o-users')->color('gray')
                ->schema([
                    Select::make('schedule_id')->label('Kelas')->required()->searchable()
                        ->options(fn () => TrainingSchedule::query()->withCount('enrollments')->orderByDesc('start_date')->limit(300)->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->code} — ".($s->title ?: $s->package?->title).' ('.$s->enrollments_count.' peserta)'])),
                    Select::make('type')->label('Jenis sertifikat')->options(Certificate::TYPES)->native(false)->helperText('Kosong = jenis sertifikat paket.'),
                ])
                ->action(function (array $data, CertificateService $svc): void {
                    $schedule = TrainingSchedule::query()->findOrFail($data['schedule_id']);
                    $result = $svc->createDraftsForSchedule($schedule, auth()->user(), $data['type'] ?: null);
                    $skipped = collect($result['skipped'])->map(fn ($s) => $s['name'].': '.implode(' ', $s['reasons']))->implode("\n");
                    Notification::make()->title(count($result['created']).' draf sertifikat dibuat untuk kelas '.$schedule->code)
                        ->body($skipped ? 'Dilewati ('.count($result['skipped']).'):'."\n".$skipped : null)
                        ->status($result['created'] ? 'success' : 'warning')->persistent()->send();
                }),
            CreateAction::make()->label('Buat draf'),
        ];
    }
}
