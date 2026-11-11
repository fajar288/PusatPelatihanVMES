<?php

namespace App\Filament\Resources\TrainingSchedules\RelationManagers;

use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificateService;
use App\Services\CompletionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

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
            ->headerActions([
                CreateAction::make()->label('Tambah peserta manual')->mutateDataUsing(fn (array $data) => $data + ['enrolled_at' => now()]),
                Action::make('completeAll')->label('Evaluasi kelulusan semua')->icon('heroicon-o-clipboard-document-check')->color('gray')->requiresConfirmation()
                    ->modalDescription('Setiap peserta berstatus terdaftar/mengikuti dievaluasi terhadap aturan kelulusan paket; yang memenuhi → Selesai (lulus), yang tidak → Tidak lulus. Status member ikut diperbarui (alumni).')
                    ->visible(fn () => self::canManageCertificates())
                    ->action(function (CompletionService $svc): void {
                        $pass = $fail = 0;
                        foreach ($this->getOwnerRecord()->enrollments()->whereIn('status', ['enrolled', 'attending'])->get() as $e) {
                            $svc->complete($e, auth()->user())->status === 'completed' ? $pass++ : $fail++;
                        }
                        Notification::make()->title("$pass lulus, $fail tidak lulus")->success()->send();
                    }),
                Action::make('certificatesAll')->label('Buat draf sertifikat kelas')->icon('heroicon-o-academic-cap')->color('gray')->requiresConfirmation()
                    ->visible(fn () => self::canManageCertificates())
                    ->action(function (CertificateService $svc): void {
                        $r = $svc->createDraftsForSchedule($this->getOwnerRecord(), auth()->user());
                        $skipped = collect($r['skipped'])->map(fn ($s) => $s['name'].': '.implode(' ', $s['reasons']))->implode("\n");
                        Notification::make()->title(count($r['created']).' draf sertifikat dibuat')->body($skipped ? "Dilewati:\n$skipped" : null)->status($r['created'] ? 'success' : 'warning')->persistent()->send();
                    }),
            ])
            ->recordActions([
                Action::make('complete')->label('Kelulusan')->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn () => self::canManageCertificates())
                    ->modalHeading(fn (Enrollment $record) => 'Kelulusan: '.$record->participant_name)
                    ->modalDescription(function (Enrollment $record, CompletionService $svc) {
                        $ev = $svc->evaluate($record);
                        $rows = collect($ev['checks'])->map(fn ($c) => ($c['ok'] ? '✅' : ($c['required'] ? '❌' : '⚪')).' '.e($c['label']).' — '.e($c['detail']))->implode('<br>');

                        return new HtmlString(($ev['eligible'] ? '<b>Memenuhi aturan kelulusan.</b>' : '<b>Belum memenuhi aturan kelulusan.</b>').'<br>'.$rows.'<br>Nilai terhitung: '.($ev['score'] ?? '-'));
                    })
                    ->schema([
                        Select::make('result')->label('Keputusan')->options(['auto' => 'Sesuai evaluasi', 'pass' => 'Lulus (paksa)', 'fail' => 'Tidak lulus (paksa)'])->default('auto')->required()->native(false),
                        TextInput::make('final_score')->label('Nilai akhir (opsional)')->numeric()->minValue(0)->maxValue(100),
                        Textarea::make('note')->label('Catatan')->rows(2),
                    ])
                    ->action(function (array $data, Enrollment $record, CompletionService $svc): void {
                        $pass = match ($data['result']) {
                            'pass' => true, 'fail' => false, default => null,
                        };
                        $e = $svc->complete($record, auth()->user(), $pass, $data['final_score'] !== null && $data['final_score'] !== '' ? (float) $data['final_score'] : null, $data['note'] ?? null);
                        Notification::make()->title($e->status === 'completed' ? 'Peserta dinyatakan lulus' : 'Peserta dinyatakan tidak lulus')->success()->send();
                    }),
                Action::make('certificate')->label('Sertifikat')->icon('heroicon-o-academic-cap')
                    ->visible(fn (Enrollment $record) => self::canManageCertificates())
                    ->url(fn (Enrollment $record) => ($c = $record->certificate) ? CertificateResource::getUrl('edit', ['record' => $c]) : null)
                    ->modalHeading(fn (Enrollment $record) => 'Buat draf sertifikat: '.$record->participant_name)
                    ->modalDescription(function (Enrollment $record, CertificateService $svc) {
                        $check = $svc->eligibility($record);
                        $rows = collect($check['evaluation']['checks'])->map(fn ($c) => ($c['ok'] ? '✅' : ($c['required'] ? '❌' : '⚪')).' '.e($c['label']).' — '.e($c['detail']))->implode('<br>');

                        return new HtmlString(($check['eligible'] ? '<b>Memenuhi syarat.</b>' : '<b>Belum memenuhi syarat:</b> '.e(implode(' ', $check['reasons']))).'<br>'.$rows);
                    })
                    ->schema([
                        Select::make('type')->label('Jenis sertifikat')->options(Certificate::TYPES)->native(false)->helperText('Kosong = jenis sertifikat paket.'),
                        Toggle::make('force')->label('Paksa meski belum memenuhi syarat')->live(),
                        TextInput::make('force_reason')->label('Alasan')->visible(fn (Get $get) => (bool) $get('force'))->required(fn (Get $get) => (bool) $get('force')),
                    ])
                    ->action(function (array $data, Enrollment $record, CertificateService $svc): void {
                        try {
                            $c = $svc->createDraft($record, auth()->user(), $data['type'] ? ['type' => $data['type']] : [], (bool) ($data['force'] ?? false), $data['force_reason'] ?? null);
                            Notification::make()->title("Draf sertifikat {$c->number} dibuat")->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    private static function canManageCertificates(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('certificates.manage') || $u?->isAdmin());
    }
}
