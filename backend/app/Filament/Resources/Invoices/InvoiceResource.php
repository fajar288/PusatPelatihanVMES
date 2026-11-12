<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Invoices\RelationManagers\RefundsRelationManager;
use App\Filament\Resources\Invoices\RelationManagers\TransactionsRelationManager;
use App\Filament\Support\EnterpriseFields;
use App\Filament\Support\FinanceFields;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\RefundService;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Invoice (Roadmap 5C): tagihan pendaftaran & Corporate Training Request dengan item, diskon, pajak, jatuh tempo.
 * Status hanya berubah lewat verifikasi finance; invoice lunas tidak menerima bukti baru; refund lewat menu Refund.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Invoice';

    protected static ?string $modelLabel = 'Invoice';

    protected static ?string $pluralModelLabel = 'Invoice';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function canViewAny(): bool
    {
        return FinanceFields::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        $rp = fn ($v) => FinanceFields::rupiah($v);

        return $schema->components([
            Section::make('Invoice')->columns(3)->components([
                TextEntry::make('invoice_number')->label('Nomor'),
                TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => Invoice::STATUSES[$state] ?? $state)->color(fn ($state) => FinanceFields::invoiceColor($state)),
                TextEntry::make('source')->label('Sumber')->state(fn (Invoice $record) => $record->registration ? 'Pendaftaran '.$record->registration->registration_number : ($record->trainingRequest ? 'Permintaan training '.$record->trainingRequest->code : '-')),
                TextEntry::make('bill_to_name')->label('Ditagihkan ke')->belowContent(fn (Invoice $record) => collect([$record->bill_to_company !== $record->bill_to_name ? $record->bill_to_company : null, $record->bill_to_email])->filter()->implode(' · ')),
                TextEntry::make('organization.legal_name')->label('Organisasi')->placeholder('- (individu)'),
                TextEntry::make('bill_to_tax_id')->label('NPWP')->placeholder('-'),
                TextEntry::make('issued_at')->label('Terbit')->date('d M Y'),
                TextEntry::make('due_date')->label('Jatuh tempo')->date('d M Y')->placeholder('-'),
                TextEntry::make('installment')->label('Termin')->state(fn (Invoice $record) => $record->installment_total > 1 ? "{$record->installment_no} dari {$record->installment_total}" : 'Penuh'),
            ]),
            Section::make('Nilai')->columns(4)->components([
                TextEntry::make('subtotal')->label('Subtotal')->formatStateUsing($rp),
                TextEntry::make('discount')->label('Diskon')->formatStateUsing($rp)->helperText(fn (Invoice $record) => $record->discount_note),
                TextEntry::make('tax_amount')->label(fn (Invoice $record) => ($record->tax_label ?: 'Pajak').' '.rtrim(rtrim(number_format((float) $record->tax_rate, 2, ',', '.'), '0'), ',').'%')->formatStateUsing($rp),
                TextEntry::make('total')->label('Total')->formatStateUsing($rp)->weight('bold'),
                TextEntry::make('amount_paid')->label('Terbayar')->formatStateUsing($rp),
                TextEntry::make('amount_refunded')->label('Dikembalikan')->formatStateUsing($rp),
                TextEntry::make('outstanding')->label('Sisa')->state(fn (Invoice $record) => $record->outstanding())->formatStateUsing($rp),
                TextEntry::make('paid_at')->label('Lunas')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('settlement_note')->label('Catatan selisih')->placeholder('-')->columnSpanFull(),
                TextEntry::make('cancel_reason')->label('Alasan batal')->placeholder('-')->visible(fn (Invoice $record) => $record->status === 'cancelled')->columnSpanFull(),
                TextEntry::make('notes')->label('Catatan pada invoice')->placeholder('-')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['registration', 'trainingRequest', 'organization'])->withCount(['transactions as pending_count' => fn ($q) => $q->where('status', 'pending')]))
            ->columns([
                TextColumn::make('invoice_number')->label('Invoice')->searchable()
                    ->description(fn (Invoice $record) => $record->installment_total > 1 ? "Termin {$record->installment_no}/{$record->installment_total}" : null),
                TextColumn::make('source')->label('Sumber')->state(fn (Invoice $record) => $record->sourceLabel())
                    ->description(fn (Invoice $record) => $record->trainingRequest ? 'Corporate request' : ($record->registration?->type === 'corporate' ? 'Pendaftaran kolektif' : 'Pendaftaran individu')),
                TextColumn::make('bill_to_name')->label('Ditagihkan ke')->searchable()->wrap()->description(fn (Invoice $record) => $record->organization?->code),
                TextColumn::make('total')->label('Total')->money('IDR', locale: 'id')->sortable(),
                TextColumn::make('amount_paid')->label('Terbayar')->money('IDR', locale: 'id')
                    ->description(fn (Invoice $record) => (float) $record->amount_refunded > 0 ? 'Refund '.FinanceFields::rupiah($record->amount_refunded) : null),
                TextColumn::make('due_date')->label('Jatuh tempo')->date('d M Y')->sortable()
                    ->color(fn (Invoice $record) => $record->isPayable() && $record->due_date?->isBefore(today()) ? 'danger' : null),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state, Invoice $record) => $record->pending_count > 0 && $record->isPayable() ? 'Menunggu verifikasi' : (Invoice::STATUSES[$state] ?? $state))
                    ->color(fn ($state, Invoice $record) => $record->pending_count > 0 && $record->isPayable() ? 'info' : FinanceFields::invoiceColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')->options(Invoice::STATUSES),
                Filter::make('awaiting')->label('Ada bukti menunggu verifikasi')->query(fn (Builder $query) => $query->whereHas('transactions', fn ($q) => $q->where('status', 'pending'))),
                Filter::make('corporate')->label('Tagihan perusahaan')->query(fn (Builder $query) => $query->whereNotNull('organization_id')),
                SelectFilter::make('organization_id')->label('Organisasi')->relationship('organization', 'legal_name')->searchable()->preload(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** Aksi di halaman detail invoice. @return list<Action> */
    public static function headerActions(): array
    {
        $open = fn (Invoice $record) => in_array($record->status, ['pending', 'failed', 'expired'], true);

        return [
            Action::make('pdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->url(fn (Invoice $record) => route('admin.invoices.pdf', $record), shouldOpenInNewTab: true),
            Action::make('record')->label('Catat pembayaran diterima')->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn (Invoice $record) => $open($record) && ! $record->hasPendingTransaction())
                ->modalDescription('Untuk dana yang sudah masuk tanpa unggahan bukti dari pembayar (mis. transfer langsung atau tunai). Transaksi langsung terverifikasi.')
                ->schema(fn (Invoice $record) => FinanceFields::recordPaymentSchema($record))
                ->action(fn (array $data, Invoice $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => app(PaymentService::class)->recordReceived($record, auth()->user(), $data), 'Pembayaran dicatat & diverifikasi')),
            Action::make('extend')->label('Perpanjang jatuh tempo')->icon('heroicon-o-calendar')->color('gray')
                ->visible($open)
                ->fillForm(fn (Invoice $record) => ['due_date' => max(today()->addDays(3), $record->due_date ?? today())])
                ->schema([DatePicker::make('due_date')->label('Jatuh tempo baru')->required()->native(false)->minDate(today())])
                ->action(fn (array $data, Invoice $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => app(InvoiceService::class)->extend($record, $data['due_date'], auth()->user()), 'Jatuh tempo diperbarui')),
            Action::make('refund')->label('Ajukan refund')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->visible(fn (Invoice $record) => app(RefundService::class)->refundable($record) > 0)
                ->modalDescription('Refund harus disetujui orang lain yang berwenang, lalu dicatat selesai dengan referensi transfer balik. Pendaftaran/enrollment TIDAK dibatalkan otomatis.')
                ->fillForm(fn (Invoice $record) => ['amount' => app(RefundService::class)->refundable($record)])
                ->schema(fn (Invoice $record) => [
                    TextInput::make('amount')->label('Nominal refund (Rp)')->numeric()->required()->minValue(1)->maxValue(app(RefundService::class)->refundable($record)),
                    Select::make('payment_transaction_id')->label('Transaksi asal (opsional)')
                        ->options($record->transactions()->where('status', 'paid')->get()->mapWithKeys(fn (PaymentTransaction $t) => [$t->id => $t->code.' · '.FinanceFields::rupiah($t->amount_received)])->all()),
                    Textarea::make('reason')->label('Alasan refund')->required()->minLength(10)->rows(3),
                ])
                ->action(fn (array $data, Invoice $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => app(RefundService::class)->request($record, auth()->user(), (float) $data['amount'], $data['reason'],
                        ! empty($data['payment_transaction_id']) ? PaymentTransaction::query()->find($data['payment_transaction_id']) : null), 'Refund diajukan — menunggu persetujuan')),
            Action::make('cancel')->label('Batalkan invoice')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Invoice $record) => $open($record) && (float) $record->amount_paid <= 0)
                ->schema([Textarea::make('reason')->label('Alasan')->required()->rows(2)])
                ->action(fn (array $data, Invoice $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => app(InvoiceService::class)->cancel($record, $data['reason'], auth()->user()), 'Invoice dibatalkan')),
        ];
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class, TransactionsRelationManager::class, RefundsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/'), 'view' => ViewInvoice::route('/{record}')];
    }
}
