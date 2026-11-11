<?php

namespace App\Filament\Resources\Certificates;

use App\Filament\Resources\Certificates\Pages\CreateCertificate;
use App\Filament\Resources\Certificates\Pages\EditCertificate;
use App\Filament\Resources\Certificates\Pages\ListCertificates;
use App\Filament\Resources\Certificates\RelationManagers\HistoryRelationManager;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Enrollment;
use App\Models\TrainingSchedule;
use App\Services\CertificateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** Sertifikat — draf → validasi → terbit → cabut/ganti; PDF + QR; riwayat (PRD §5.10). */
class CertificateResource extends Resource
{
    protected static ?string $model = Certificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Sertifikat';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Sertifikat';

    protected static ?string $modelLabel = 'Sertifikat';

    protected static ?string $pluralModelLabel = 'Sertifikat';

    protected static ?string $recordTitleAttribute = 'number';

    /** URL panel memakai id (route key model = number dipakai API). */
    protected static ?string $recordRouteKeyName = 'id';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('certificates.manage') || $u?->hasPermission('certificates.issue') || $u?->isAdmin());
    }

    public static function canIssue(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('certificates.issue') || $u?->isSuperAdmin());
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'issued' => 'success', 'pending_validation' => 'warning', 'revoked' => 'danger', 'replaced' => 'gray', default => 'info',
        };
    }

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?Certificate $record) => $record !== null && ! $record->isEditable();

        return $schema->components([
            Section::make('Peserta & kelas')->columns(3)->visibleOn('create')->components([
                Select::make('enrollment_id')->label('Peserta (enrollment)')->required()->searchable()->columnSpan(2)
                    ->getSearchResultsUsing(fn (string $search) => Enrollment::query()->with('schedule')->where('participant_name', 'like', "%{$search}%")
                        ->orWhereHas('schedule', fn ($query) => $query->where('code', 'like', "%{$search}%"))->limit(50)->get()
                        ->mapWithKeys(fn (Enrollment $e) => [$e->id => self::enrollmentLabel($e)]))
                    ->getOptionLabelUsing(fn ($value) => ($e = Enrollment::query()->with('schedule')->find($value)) ? self::enrollmentLabel($e) : null)
                    ->options(fn () => Enrollment::query()->with('schedule')->where('status', 'completed')->latest('completed_at')->limit(100)->get()->mapWithKeys(fn (Enrollment $e) => [$e->id => self::enrollmentLabel($e)]))
                    ->live()
                    ->helperText('Daftar awal: peserta berstatus lulus. Ketik nama/kode kelas untuk mencari peserta lain.'),
                Select::make('type')->label('Jenis sertifikat')->options(Certificate::TYPES)->native(false)
                    ->default(fn (Get $get) => Enrollment::query()->with('schedule.package')->find($get('enrollment_id'))?->schedule?->package?->certificate_type ?? 'participation')
                    ->helperText('Kosong = mengikuti jenis sertifikat paket.'),
                Select::make('template_id')->label('Template')->options(fn () => CertificateTemplate::query()->active()->orderBy('type')->pluck('name', 'id'))->native(false)->helperText('Kosong = template paket / bawaan jenis.'),
                DatePicker::make('issued_at')->label('Tanggal sertifikat')->native(false)->helperText('Kosong = tanggal selesai kelas.'),
                Placeholder::make('eligibility')->label('Kelayakan')->columnSpanFull()
                    ->content(function (Get $get) {
                        $e = Enrollment::query()->with('schedule.package')->find($get('enrollment_id'));
                        if (! $e) {
                            return 'Pilih peserta untuk melihat pemeriksaan aturan kelulusan.';
                        }
                        $check = app(CertificateService::class)->eligibility($e, $get('type') ?: null);
                        $rows = collect($check['evaluation']['checks'])->map(fn ($c) => ($c['ok'] ? '✅' : ($c['required'] ? '❌' : '⚪')).' '.e($c['label']).' — '.e($c['detail']))->implode('<br>');
                        // Utilitas text-success-600/text-danger-600 tidak ada di CSS Filament terkompilasi → pakai komponen badge.
                        $head = Blade::render(
                            '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-bottom:.25rem"><x-filament::badge :color="$color">{{ $label }}</x-filament::badge>@if ($reasons)<span>{{ $reasons }}</span>@endif</div>',
                            [
                                'color' => $check['eligible'] ? 'success' : 'danger',
                                'label' => $check['eligible'] ? 'Memenuhi syarat' : 'Belum memenuhi syarat',
                                'reasons' => $check['eligible'] ? '' : implode(' ', $check['reasons']),
                            ],
                        );

                        return new HtmlString($head.$rows);
                    }),
                Toggle::make('force')->label('Paksa buat draf meski belum memenuhi syarat (admin)')->live()->columnSpan(2),
                TextInput::make('force_reason')->label('Alasan')->visible(fn (Get $get) => (bool) $get('force'))->required(fn (Get $get) => (bool) $get('force'))->maxLength(255),
            ]),
            Section::make('Status')->columns(4)->visibleOn('edit')->components([
                Placeholder::make('number_info')->label('Nomor')->content(fn (?Certificate $record) => $record?->number ?? '-'),
                Placeholder::make('status_info')->label('Status')->content(fn (?Certificate $record) => $record ? new HtmlString('<b>'.e($record->statusLabel()).'</b>'.($record->isExpired() ? ' (kedaluwarsa)' : '')) : '-'),
                Placeholder::make('class_info')->label('Kelas')->content(fn (?Certificate $record) => new HtmlString(e($record?->class_code ?? '-').(filled($record?->enrollment?->participant_name) ? '<br>'.e($record->enrollment->participant_name) : ''))),
                Placeholder::make('verify_info')->label('Verifikasi')->content(fn (?Certificate $record) => $record ? new HtmlString(Blade::render('<x-filament::link :href="$url" target="_blank" rel="noopener" style="max-width:100%;overflow-wrap:anywhere">{{ $url }}</x-filament::link>', ['url' => $record->verificationUrl()])) : '-'),
                Placeholder::make('flow_info')->label('Riwayat singkat')->columnSpanFull()->content(fn (?Certificate $record) => $record ? new HtmlString(implode('<br>', array_filter([
                    'Dibuat '.($record->created_at?->format('d M Y H:i') ?? '-').' oleh '.e($record->creator?->name ?? '-'),
                    $record->submitted_at ? 'Diajukan '.$record->submitted_at->format('d M Y H:i') : null,
                    $record->issued_on ? 'Terbit '.$record->issued_on->format('d M Y H:i').' oleh '.e($record->issuer?->name ?? '-') : null,
                    $record->revoked_at ? 'Dicabut '.$record->revoked_at->format('d M Y H:i').': '.e($record->revoke_reason ?? '') : null,
                    $record->replacedBy ? 'Digantikan oleh '.e($record->replacedBy->number) : null,
                    $record->replaces ? 'Menggantikan '.e($record->replaces->number) : null,
                ]))) : '-'),
            ]),
            Section::make('Isi sertifikat')->columns(3)->visibleOn('edit')->disabled($locked)->components([
                TextInput::make('recipient_name')->label('Nama penerima (sesuai sertifikat)')->required()->maxLength(255),
                TextInput::make('program_title')->label('Program')->required()->maxLength(255)->columnSpan(2),
                DatePicker::make('issued_at')->label('Tanggal sertifikat')->native(false)->required(),
                DatePicker::make('valid_until')->label('Berlaku hingga')->native(false)->helperText('Kosong = tanpa batas.'),
                TextInput::make('hours')->label('Jam pelajaran')->numeric()->minValue(0),
                TextInput::make('score')->label('Nilai')->numeric()->minValue(0)->maxValue(100),
                TextInput::make('predicate')->label('Predikat')->maxLength(40),
                Select::make('type')->label('Jenis')->options(Certificate::TYPES)->native(false)->required(),
                TextInput::make('signatory_name')->label('Penandatangan')->maxLength(255),
                TextInput::make('signatory_title')->label('Jabatan penandatangan')->maxLength(255),
                Select::make('template_id')->label('Template')->options(fn () => CertificateTemplate::query()->orderBy('type')->pluck('name', 'id'))->native(false),
                TagsInput::make('competencies')->label('Materi / unit kompetensi (lampiran)')->columnSpanFull(),
            ]),
            Section::make('Catatan')->visibleOn('edit')->components([
                Textarea::make('notes')->label('Catatan internal')->rows(3),
            ]),
        ]);
    }

    public static function enrollmentLabel(Enrollment $e): string
    {
        return $e->participant_name.' — '.($e->schedule?->code ?? '?').' ('.(['enrolled' => 'terdaftar', 'attending' => 'mengikuti', 'completed' => 'lulus', 'failed' => 'tidak lulus', 'dropped' => 'mundur'][$e->status] ?? $e->status).')';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['enrollment', 'schedule', 'template']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('Nomor')->searchable()->sortable()->copyable(),
                TextColumn::make('recipient_name')->label('Penerima')->searchable()->description(fn (Certificate $c) => $c->recipient_email),
                TextColumn::make('program_title')->label('Program')->limit(36)->description(fn (Certificate $c) => $c->class_code),
                TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn ($state) => Certificate::TYPES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'competency' ? 'warning' : ($state === 'vendor' ? 'info' : 'success')),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Certificate::STATUSES[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                TextColumn::make('issued_at')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('valid_until')->label('Berlaku hingga')->date('d M Y')->placeholder('∞'),
                TextColumn::make('issuer.name')->label('Diterbitkan oleh')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(Certificate::STATUSES),
                SelectFilter::make('type')->options(Certificate::TYPES),
                SelectFilter::make('schedule_id')->label('Kelas')->options(fn () => TrainingSchedule::query()->orderByDesc('start_date')->limit(300)->pluck('code', 'id')),
            ])
            ->recordActions([
                Action::make('pdf')->label('PDF')->icon('heroicon-o-document-text')->url(fn (Certificate $record) => route('admin.certificates.pdf', $record->id))->openUrlInNewTab(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('issue')->label('Terbitkan yang dipilih')->icon('heroicon-o-check-badge')->color('success')->requiresConfirmation()
                    ->visible(fn () => self::canIssue())
                    ->action(function (Collection $records, CertificateService $svc): void {
                        $ok = 0;
                        $fail = [];
                        foreach ($records as $c) {
                            try {
                                $svc->issue($c, auth()->user());
                                $ok++;
                            } catch (ValidationException $e) {
                                $fail[] = $c->number.': '.$e->getMessage();
                            }
                        }
                        Notification::make()->title("$ok sertifikat diterbitkan".($fail ? '; '.count($fail).' gagal' : ''))->body(implode("\n", $fail))->status($fail ? 'warning' : 'success')->send();
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('submit')->label('Ajukan validasi')->icon('heroicon-o-paper-airplane')->requiresConfirmation()
                    ->action(function (Collection $records, CertificateService $svc): void {
                        $ok = 0;
                        foreach ($records as $c) {
                            if ($c->status === 'draft') {
                                $svc->submit($c, auth()->user());
                                $ok++;
                            }
                        }
                        Notification::make()->title("$ok draf diajukan untuk validasi")->success()->send();
                    })->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getRelations(): array
    {
        return [HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCertificates::route('/'),
            'create' => CreateCertificate::route('/create'),
            'edit' => EditCertificate::route('/{record}/edit'),
        ];
    }
}
