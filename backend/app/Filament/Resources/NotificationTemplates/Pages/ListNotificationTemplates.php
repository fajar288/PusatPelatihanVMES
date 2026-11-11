<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\NotificationTemplate;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\NotificationCenter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;

class ListNotificationTemplates extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;

    public function mount(): void
    {
        NotificationTemplate::ensureDefaults();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('announce')->label('Kirim pengumuman')->icon('heroicon-o-megaphone')
                ->modalDescription('Dikirim sebagai notifikasi aplikasi, push, dan email (sesuai preferensi tiap penerima) memakai template "Pengumuman".')
                ->schema([
                    Select::make('target')->label('Penerima')->required()->native(false)->live()->default('class')->options([
                        'class' => 'Peserta satu kelas',
                        'active' => 'Semua peserta kelas yang sedang berjalan/akan datang',
                        'alumni' => 'Semua alumni',
                        'members' => 'Semua member',
                    ]),
                    Select::make('schedule_id')->label('Kelas')->searchable()->native(false)
                        ->visible(fn (Get $get) => $get('target') === 'class')->required(fn (Get $get) => $get('target') === 'class')
                        ->options(fn () => TrainingSchedule::query()->with('package')->orderByDesc('start_date')->limit(200)->get()
                            ->mapWithKeys(fn (TrainingSchedule $s) => [$s->id => $s->code.' - '.($s->title ?? $s->package?->title)])->all()),
                    TextInput::make('title')->label('Judul')->required()->maxLength(120),
                    Textarea::make('message')->label('Pesan')->required()->rows(4)->maxLength(1000),
                    TextInput::make('url')->label('Tautan di portal (opsional)')->placeholder('/portal/pelatihan')->maxLength(255)
                        ->rule('regex:/^\/[A-Za-z0-9\/_\-?=&.]*$/'),
                ])
                ->action(function (array $data, NotificationCenter $center): void {
                    $users = $this->recipients($data);
                    if ($users->isEmpty()) {
                        Notification::make()->title('Tidak ada penerima dengan akun portal untuk target ini.')->warning()->send();

                        return;
                    }
                    $sent = $center->send($users, 'announcement', ['title' => $data['title'], 'message' => $data['message']], $data['url'] ?: '/portal/notifikasi');
                    AuditLog::record('notification.announcement', null, null, ['target' => $data['target'], 'schedule_id' => $data['schedule_id'] ?? null, 'title' => $data['title'], 'recipients' => $sent]);
                    Notification::make()->title("Pengumuman dikirim ke $sent penerima")->success()->send();
                }),
        ];
    }

    /** @return Collection<int, User> */
    private function recipients(array $data): Collection
    {
        return match ($data['target']) {
            'class' => NotificationCenter::usersForEnrollments(Enrollment::query()->where('schedule_id', $data['schedule_id'])->whereIn('status', ['enrolled', 'attending', 'completed'])->get()),
            'active' => NotificationCenter::usersForEnrollments(Enrollment::query()->whereIn('status', ['enrolled', 'attending'])
                ->whereHas('schedule', fn ($q) => $q->whereDate('end_date', '>=', today()))->get()),
            'alumni' => User::query()->whereHas('member', fn ($q) => $q->where('status', 'alumni'))->get(),
            default => User::query()->whereHas('member')->get(),
        };
    }
}
