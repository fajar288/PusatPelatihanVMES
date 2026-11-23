<?php

namespace App\Filament\Resources\DataDeletionRequests;

use App\Filament\NavigationGroup;
use App\Filament\Resources\DataDeletionRequests\Pages\ListDataDeletionRequests;
use App\Filament\Resources\DataDeletionRequests\Pages\ViewDataDeletionRequest;
use App\Filament\Support\EnterpriseFields;
use App\Filament\Support\Layout;
use App\Models\DataDeletionRequest;
use App\Services\DataDeletionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Permintaan penghapusan data pribadi dari peserta. Hanya peran dengan izin `privacy.manage`. Persetujuan langsung
 * menjalankan penghapusan (PrivacyService::eraseAccount) dan tidak bisa dibatalkan; setiap aksi diaudit.
 */
class DataDeletionRequestResource extends Resource
{
    protected static ?string $model = DataDeletionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Permintaan hapus data';

    protected static ?string $modelLabel = 'Permintaan hapus data';

    protected static ?string $pluralModelLabel = 'Permintaan hapus data';

    protected static ?string $slug = 'permintaan-hapus-data';

    public const STATUS_COLORS = ['pending' => 'warning', 'approved' => 'info', 'completed' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray'];

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('privacy.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** Jumlah permintaan aktif yang lewat/mendekati tenggat sebagai penanda di menu. */
    public static function getNavigationBadge(): ?string
    {
        $n = DataDeletionRequest::query()->nearDeadline()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Permintaan yang lewat atau mendekati tenggat proses';
    }

    public static function infolist(Schema $schema): Schema
    {
        $service = app(DataDeletionService::class);

        return $schema->components([
            Section::make('Permintaan')->columns(Layout::cols(3))->components([
                TextEntry::make('account_label')->label('Akun'),
                TextEntry::make('user.name')->label('Nama saat ini'),
                TextEntry::make('user.email')->label('Email saat ini')->copyable(),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (DataDeletionRequest $record) => $record->statusLabel())
                    ->color(fn (?string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextEntry::make('requested_at')->label('Diajukan')->dateTime('d M Y H:i'),
                TextEntry::make('due_at')->label('Tenggat proses')->dateTime('d M Y')
                    ->color(fn (DataDeletionRequest $record) => $record->isOverdue() ? 'danger' : ($record->isNearDeadline() ? 'warning' : null))
                    ->helperText(fn (DataDeletionRequest $record) => $record->isOverdue() ? 'Lewat tenggat' : ($record->isNearDeadline() ? 'Mendekati tenggat' : null)),
                TextEntry::make('reason')->label('Alasan peserta')->placeholder('Tidak diisi')->columnSpanFull(),
                TextEntry::make('processor.name')->label('Diproses oleh')->placeholder('-'),
                TextEntry::make('processed_at')->label('Waktu diproses')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('completed_at')->label('Selesai')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('admin_note')->label('Catatan / alasan admin')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Penghalang dan peringatan')
                ->description('Penghalang harus selesai sebelum permintaan bisa disetujui. Untuk permintaan aktif, dihitung ulang dari data saat ini.')
                ->components([
                    TextEntry::make('blockers_view')->label('Penghalang')
                        ->state(fn (DataDeletionRequest $record) => self::list($record->isActive() ? $service->blockers($record->user) : ($record->blockers ?? []), 'Tidak ada')),
                    TextEntry::make('warnings_view')->label('Peringatan')
                        ->state(fn (DataDeletionRequest $record) => self::list($record->isActive() ? $service->warnings($record->user) : array_map(fn ($m) => ['message' => $m], $record->summary['warnings'] ?? []), 'Tidak ada'))
                        ->visible(fn (DataDeletionRequest $record) => $record->status !== 'rejected'),
                ]),
            Section::make('Ringkasan data akun')
                ->description('Untuk permintaan aktif dihitung dari data saat ini; setelah diproses memakai ringkasan saat disetujui.')
                ->columns(Layout::cols(3))
                ->visible(fn (DataDeletionRequest $record) => $record->isActive() || $record->summary)
                ->components([
                    ...collect([
                        'registrations' => 'Pendaftaran', 'enrollments' => 'Enrollment', 'enrollments_running' => 'Enrollment berjalan',
                        'invoices' => 'Invoice', 'invoices_unpaid' => 'Invoice belum lunas', 'certificates' => 'Sertifikat terbit',
                        'corporate_access' => 'Akses portal perusahaan',
                    ])->map(fn (string $label, string $key) => TextEntry::make("summary_$key")->label($label)
                        ->state(fn (DataDeletionRequest $record) => (string) (self::summaryFor($record, $service)[$key] ?? 0)))->values()->all(),
                    TextEntry::make('summary_organizations')->label('Keterikatan perusahaan')->columnSpanFull()
                        ->state(fn (DataDeletionRequest $record) => implode(', ', self::summaryFor($record, $service)['organizations'] ?? []) ?: 'Tidak ada'),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    private static function summaryFor(DataDeletionRequest $record, DataDeletionService $service): array
    {
        return $record->isActive() && $record->status === 'pending' ? $service->summary($record->user) : ($record->summary ?? []);
    }

    /** @param list<array{message: string}> $items */
    private static function list(array $items, string $empty): HtmlString
    {
        if ($items === []) {
            return new HtmlString(e($empty));
        }

        return new HtmlString('<ul style="list-style: disc; padding-left: 1.25rem; margin: 0;">'
            .implode('', array_map(fn ($i) => '<li>'.e($i['message']).'</li>', $items)).'</ul>');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'processor']))
            ->columns([
                TextColumn::make('account_label')->label('Akun')->searchable()
                    ->description(fn (DataDeletionRequest $record) => $record->status === 'completed' ? null : $record->user?->name),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (DataDeletionRequest $record) => $record->statusLabel())
                    ->color(fn (?string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('requested_at')->label('Diajukan')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('due_at')->label('Tenggat')->date('d M Y')->sortable()
                    ->color(fn (DataDeletionRequest $record) => $record->isOverdue() ? 'danger' : ($record->isNearDeadline() ? 'warning' : null))
                    ->description(fn (DataDeletionRequest $record) => $record->isOverdue() ? 'Lewat tenggat' : ($record->isNearDeadline() ? 'Mendekati tenggat' : null)),
                TextColumn::make('processor.name')->label('Diproses oleh')->placeholder('-')
                    ->description(fn (DataDeletionRequest $record) => $record->processed_at?->format('d M Y H:i')),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(DataDeletionRequest::STATUSES),
                Filter::make('near_deadline')->label('Lewat / mendekati tenggat')->query(fn (Builder $query) => $query->nearDeadline()),
            ])
            ->recordActions([
                ViewAction::make()->label('Detail'),
            ]);
    }

    /** Aksi Setujui & Tolak (halaman detail). @return list<Action> */
    public static function decisionActions(): array
    {
        return [
            Action::make('approve')->label('Setujui & hapus data')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn (DataDeletionRequest $record) => $record->status === 'pending')
                ->requiresConfirmation()
                ->modalHeading('Setujui dan hapus data pribadi?')->modalSubmitActionLabel('Ya, hapus data')
                ->modalDescription('Data identitas akun dianonimkan, berkas pribadi dihapus, login dinonaktifkan, dan semua sesi dicabut. Invoice dan bukti bayar tetap disimpan, sertifikat tetap sah dengan nama disamarkan. Tindakan ini tidak dapat dibatalkan.')
                ->schema([Textarea::make('note')->label('Catatan untuk peserta (opsional)')->rows(2)->maxLength(500)])
                ->action(function (array $data, DataDeletionRequest $record, Action $action): void {
                    EnterpriseFields::attempt($action, fn () => app(DataDeletionService::class)->approve($record, auth()->user(), $data['note'] ?? null));
                    Notification::make()->title('Data pribadi telah dihapus')->body('Permintaan selesai dan peserta sudah dikirimi email terakhir.')->success()->send();
                }),
            Action::make('reject')->label('Tolak')->icon(Heroicon::OutlinedXCircle)->color('gray')
                ->visible(fn (DataDeletionRequest $record) => $record->status === 'pending')
                ->modalSubmitActionLabel('Tolak permintaan')
                ->schema([Textarea::make('reason')->label('Alasan penolakan')->helperText('Dikirim ke peserta.')->required()->rows(3)->maxLength(1000)])
                ->action(fn (array $data, DataDeletionRequest $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => app(DataDeletionService::class)->reject($record, auth()->user(), (string) $data['reason']), 'Permintaan ditolak')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataDeletionRequests::route('/'),
            'view' => ViewDataDeletionRequest::route('/{record}'),
        ];
    }
}
