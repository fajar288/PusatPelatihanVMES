<?php

namespace App\Filament\Resources\TrainingRequests;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\TrainingRequests\Pages\ListTrainingRequests;
use App\Filament\Resources\TrainingRequests\Pages\ViewTrainingRequest;
use App\Filament\Resources\TrainingRequests\RelationManagers\SeatsRelationManager;
use App\Filament\Support\EnterpriseFields;
use App\Filament\Support\FinanceFields;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use App\Services\InvoiceService;
use App\Services\TrainingRequestService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Corporate Training Request (Roadmap 5 §4.2, §20.13) dari sisi VMES: review & penawaran, konfirmasi pembayaran manual,
 * penetapan batch (kursi berisi peserta otomatis menjadi enrollment CORPORATE), pembatalan. Perusahaan mengisi peserta
 * dari Corporate Portal.
 */
class TrainingRequestResource extends Resource
{
    protected static ?string $model = TrainingRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Corporate;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Permintaan training';

    protected static ?string $modelLabel = 'Permintaan training';

    protected static ?string $pluralModelLabel = 'Permintaan training';

    protected static ?string $recordTitleAttribute = 'code';

    public static function canViewAny(): bool
    {
        return EnterpriseFields::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = TrainingRequest::query()->whereIn('status', ['submitted', 'approved'])->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Perlu penawaran / konfirmasi pembayaran';
    }

    public static function infolist(Schema $schema): Schema
    {
        $rupiah = fn ($v) => $v === null ? '-' : 'Rp '.number_format((float) $v, 0, ',', '.');

        return $schema->components([
            Section::make('Permintaan')->columns(3)->components([
                TextEntry::make('code')->label('Kode'),
                TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => TrainingRequest::STATUSES[$state] ?? $state),
                TextEntry::make('organization.legal_name')->label('Organisasi'),
                TextEntry::make('package.title')->label('Program'),
                TextEntry::make('seats')->label('Kursi'),
                TextEntry::make('delivery')->label('Delivery')->formatStateUsing(fn ($state) => TrainingRequest::DELIVERY[$state] ?? $state),
                TextEntry::make('target_date')->label('Target tanggal')->date('d M Y')->placeholder('-'),
                TextEntry::make('location')->label('Lokasi')->placeholder('-'),
                TextEntry::make('unit.name')->label('Unit / cost center')->placeholder('-'),
                TextEntry::make('trainer_requirements')->label('Kebutuhan trainer')->placeholder('-')->columnSpanFull(),
                TextEntry::make('notes')->label('Catatan perusahaan')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Penawaran, pembayaran & jadwal')->columns(3)->components([
                TextEntry::make('price_per_seat')->label('Harga / kursi')->formatStateUsing($rupiah)->placeholder('-'),
                TextEntry::make('quote_discount')->label('Diskon')->formatStateUsing($rupiah),
                TextEntry::make('quote_total')->label('Total')->formatStateUsing($rupiah)->placeholder('-'),
                TextEntry::make('quote_valid_until')->label('Berlaku sampai')->date('d M Y')->placeholder('-'),
                TextEntry::make('paid_at')->label('Lunas')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('payment_reference')->label('Referensi pembayaran')->placeholder('-'),
                TextEntry::make('schedule.code')->label('Batch')->placeholder('-'),
                TextEntry::make('cancel_reason')->label('Alasan batal')->placeholder('-'),
                TextEntry::make('internal_notes')->label('Catatan internal VMES')->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['organization', 'package', 'schedule'])->withCount(['requestSeats as filled_count' => fn ($q) => $q->whereIn('status', ['assigned', 'enrolled'])]))
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable(),
                TextColumn::make('organization.legal_name')->label('Organisasi')->searchable(),
                TextColumn::make('package.title')->label('Program')->wrap(),
                TextColumn::make('seats')->label('Kursi')->formatStateUsing(fn (TrainingRequest $record) => "{$record->filled_count}/{$record->seats} terisi"),
                TextColumn::make('target_date')->label('Target')->date('d M Y')->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => TrainingRequest::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'submitted', 'approved' => 'warning', 'quoted', 'approval' => 'info', 'paid', 'scheduled', 'in_progress' => 'primary', 'completed' => 'success', default => 'gray'
                    }),
                TextColumn::make('quote_total')->label('Nilai')->money('IDR', locale: 'id')->placeholder('-')->toggleable(),
                TextColumn::make('schedule.code')->label('Batch')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(TrainingRequest::STATUSES),
                SelectFilter::make('organization_id')->label('Organisasi')->relationship('organization', 'legal_name')->searchable()->preload(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** Aksi VMES (dipakai di halaman detail). @return list<Action> */
    public static function workflowActions(): array
    {
        $svc = fn () => app(TrainingRequestService::class);

        return [
            Action::make('quote')->label(fn (TrainingRequest $record) => $record->status === 'quoted' ? 'Perbarui penawaran' : 'Buat penawaran')
                ->icon('heroicon-o-document-currency-dollar')->color('primary')
                ->visible(fn (TrainingRequest $record) => in_array($record->status, ['submitted', 'quoted'], true))
                ->fillForm(fn (TrainingRequest $record) => ['price_per_seat' => $record->price_per_seat ?? $record->package?->price, 'discount' => $record->quote_discount ?? 0,
                    'valid_until' => $record->quote_valid_until ?? today()->addDays(14), 'notes' => $record->quote_notes])
                ->schema([
                    TextInput::make('price_per_seat')->label('Harga per kursi (Rp)')->numeric()->required()->minValue(0),
                    TextInput::make('discount')->label('Diskon total (Rp)')->numeric()->default(0)->minValue(0),
                    DatePicker::make('valid_until')->label('Berlaku sampai')->required()->native(false),
                    Textarea::make('notes')->label('Catatan penawaran (tampil ke perusahaan)')->rows(3),
                ])
                ->action(fn (array $data, TrainingRequest $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => $svc()->quote($record, auth()->user(), (float) $data['price_per_seat'], (float) ($data['discount'] ?? 0), $data['valid_until'], $data['notes'] ?? null), 'Penawaran dikirim ke perusahaan')),

            // 5C: status "Pembayaran diterima" hanya lewat verifikasi transaksi pada invoice (bukan tombol konfirmasi manual)
            Action::make('invoice')->label(fn (TrainingRequest $record) => 'Invoice '.$record->invoices()->where('status', '!=', 'cancelled')->value('invoice_number'))
                ->icon('heroicon-o-document-currency-dollar')->color('success')
                ->visible(fn (TrainingRequest $record) => FinanceFields::canManage() && $record->invoices()->where('status', '!=', 'cancelled')->exists())
                ->url(fn (TrainingRequest $record) => InvoiceResource::getUrl('view', ['record' => $record->invoices()->where('status', '!=', 'cancelled')->first()])),
            Action::make('createInvoice')->label('Buat invoice')->icon('heroicon-o-document-plus')->color('success')
                ->visible(fn (TrainingRequest $record) => $record->status === 'approved' && FinanceFields::canManage() && ! $record->invoices()->where('status', '!=', 'cancelled')->exists())
                ->modalDescription('Invoice dibuat dari penawaran yang disetujui (biasanya otomatis saat persetujuan).')
                ->action(fn (TrainingRequest $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => app(InvoiceService::class)->forTrainingRequest($record, auth()->user()), 'Invoice dibuat')),

            Action::make('schedule')->label(fn (TrainingRequest $record) => $record->schedule_id ? 'Ganti batch' : 'Tetapkan batch')
                ->icon('heroicon-o-calendar-days')->color('primary')
                ->visible(fn (TrainingRequest $record) => in_array($record->status, ['paid', 'scheduled'], true))
                ->modalDescription('Kursi yang sudah berisi peserta langsung didaftarkan sebagai enrollment CORPORATE bersponsor organisasi ini.')
                ->schema([
                    Select::make('schedule_id')->label('Batch (jadwal) program yang sama')->required()->searchable()
                        ->options(fn (TrainingRequest $record) => TrainingSchedule::query()->where('package_id', $record->package_id)->whereDate('end_date', '>=', today())->orderBy('start_date')
                            ->get()->mapWithKeys(fn (TrainingSchedule $s) => [$s->id => $s->code.' · '.$s->start_date->format('d M Y').' · '.($s->city ?? $s->mode).' · sisa '.$s->remaining().' kursi'])->all())
                        ->helperText('Batch khusus perusahaan dibuat dulu di menu Jadwal (status boleh tertutup untuk umum).'),
                ])
                ->action(fn (array $data, TrainingRequest $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => $svc()->schedule($record, TrainingSchedule::query()->findOrFail($data['schedule_id']), auth()->user()), 'Batch ditetapkan')),

            Action::make('cancel')->label('Batalkan')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (TrainingRequest $record) => in_array($record->status, ['draft', 'submitted', 'quoted', 'approval', 'approved', 'paid', 'scheduled'], true))
                ->schema([Textarea::make('reason')->label('Alasan (dikirim ke perusahaan)')->required()->rows(2)])
                ->action(fn (array $data, TrainingRequest $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => $svc()->cancel($record, auth()->user(), $data['reason']), 'Permintaan dibatalkan')),

            Action::make('internal')->label('Catatan internal')->icon('heroicon-o-lock-closed')->color('gray')
                ->fillForm(fn (TrainingRequest $record) => ['internal_notes' => $record->internal_notes])
                ->schema([Textarea::make('internal_notes')->label('Catatan internal VMES (tidak tampil ke perusahaan)')->rows(4)])
                ->action(fn (array $data, TrainingRequest $record) => $record->update(['internal_notes' => $data['internal_notes']])),
        ];
    }

    public static function getRelations(): array
    {
        return [SeatsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingRequests::route('/'),
            'view' => ViewTrainingRequest::route('/{record}'),
        ];
    }
}
