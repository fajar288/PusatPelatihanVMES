<?php

namespace App\Filament\Resources\PaymentTransactions;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Filament\Support\EnterpriseFields;
use App\Filament\Support\FinanceFields;
use App\Models\PaymentTransaction;
use App\Services\PaymentService;
use App\Support\Media;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Antrean verifikasi pembayaran manual (Roadmap 5C): setiap bukti transfer = transaksi. Verifikasi → invoice lunas
 * (pendaftaran terkonfirmasi / permintaan training lunas); tolak → invoice bisa dibayar ulang. Idempoten & diaudit.
 */
class PaymentTransactionResource extends Resource
{
    protected static ?string $model = PaymentTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Verifikasi pembayaran';

    protected static ?string $modelLabel = 'Transaksi pembayaran';

    protected static ?string $pluralModelLabel = 'Transaksi pembayaran';

    protected static ?string $slug = 'payment-transactions';

    public static function canViewAny(): bool
    {
        return FinanceFields::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = PaymentTransaction::query()->where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['invoice.registration', 'invoice.trainingRequest', 'invoice.organization', 'submitter', 'verifier', 'statementLine']))
            ->columns(self::columns(true))
            ->filters([
                SelectFilter::make('status')->options(PaymentTransaction::STATUSES)->default('pending'),
                SelectFilter::make('source')->label('Sumber')->options(['member' => 'Portal member', 'corporate' => 'Portal perusahaan', 'admin' => 'Dicatat finance', 'migration' => 'Data lama']),
                Filter::make('difference')->label('Ada selisih nominal')->query(fn (Builder $query) => $query->whereNotNull('difference')->where('difference', '!=', 0)),
            ])
            ->recordActions(self::reviewActions());
    }

    /** @return list<TextColumn> */
    public static function columns(bool $withInvoice): array
    {
        return array_values(array_filter([
            TextColumn::make('code')->label('Transaksi')->searchable()->description(fn (PaymentTransaction $record) => $record->methodLabel()),
            $withInvoice ? TextColumn::make('invoice.invoice_number')->label('Invoice')->searchable()
                ->description(fn (PaymentTransaction $record) => $record->invoice?->sourceLabel().' · '.$record->invoice?->bill_to_name)
                ->url(fn (PaymentTransaction $record) => $record->invoice ? InvoiceResource::getUrl('view', ['record' => $record->invoice]) : null) : null,
            TextColumn::make('amount')->label('Dinyatakan')->money('IDR', locale: 'id')
                ->description(fn (PaymentTransaction $record) => $record->invoice && $record->status === 'pending' ? 'Sisa tagihan '.FinanceFields::rupiah($record->invoice->outstanding()) : null),
            TextColumn::make('amount_received')->label('Diterima')->money('IDR', locale: 'id')->placeholder('-')
                ->description(fn (PaymentTransaction $record) => $record->difference !== null && (float) $record->difference != 0
                    ? ((float) $record->difference > 0 ? 'Lebih ' : 'Kurang ').FinanceFields::rupiah(abs((float) $record->difference)) : null),
            TextColumn::make('transfer_date')->label('Tgl transfer')->date('d M Y')->placeholder('-')
                ->description(fn (PaymentTransaction $record) => $record->statementLine ? 'Cocok mutasi '.$record->statementLine->posted_on->format('d M') : null),
            TextColumn::make('destination')->label('Rekening tujuan')->placeholder('-')->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => PaymentTransaction::STATUSES[$state] ?? $state)
                ->color(fn ($state) => FinanceFields::transactionColor($state)),
            TextColumn::make('proof_path')->label('Bukti')->formatStateUsing(fn ($state) => $state ? 'Lihat bukti' : '-')
                ->url(fn (PaymentTransaction $record) => Media::url($record->proof_path), shouldOpenInNewTab: true),
            TextColumn::make('submitted_at')->label('Diunggah')->dateTime('d M Y H:i')
                ->description(fn (PaymentTransaction $record) => $record->submitter?->name),
            TextColumn::make('verifier.name')->label('Diverifikasi')->placeholder('-')->toggleable()
                ->description(fn (PaymentTransaction $record) => $record->review_note ? str($record->review_note)->limit(40)->toString() : null),
        ]));
    }

    /** Aksi verifikasi/tolak — dipakai di antrean dan di halaman invoice. @return list<Action> */
    public static function reviewActions(): array
    {
        return [
            Action::make('verify')->label('Verifikasi')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (PaymentTransaction $record) => $record->status === 'pending' && FinanceFields::canManage())
                ->modalHeading(fn (PaymentTransaction $record) => "Verifikasi {$record->code} — invoice {$record->invoice?->invoice_number}")
                ->modalDescription(fn (PaymentTransaction $record) => 'Total '.FinanceFields::rupiah($record->invoice?->total).' · sisa '.FinanceFields::rupiah($record->invoice?->outstanding())
                    .' · dinyatakan '.FinanceFields::rupiah($record->amount).($record->destination ? ' ke '.$record->destination : ''))
                ->fillForm(fn (PaymentTransaction $record) => ['amount_received' => (float) $record->amount, 'transfer_date' => $record->transfer_date])
                ->schema(FinanceFields::verifySchema())
                ->action(fn (array $data, PaymentTransaction $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => app(PaymentService::class)->verify($record, auth()->user(), (float) $data['amount_received'], $data['transfer_date'] ?? null, $data['note'] ?? null, (bool) ($data['settle'] ?? false)),
                    'Pembayaran diverifikasi')),
            Action::make('reject')->label('Tolak')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (PaymentTransaction $record) => $record->status === 'pending' && FinanceFields::canManage())
                ->schema([Textarea::make('reason')->label('Alasan (dikirim ke pembayar)')->required()->rows(2)])
                ->action(fn (array $data, PaymentTransaction $record, Action $action) => EnterpriseFields::attempt($action,
                    fn () => app(PaymentService::class)->reject($record, auth()->user(), $data['reason']), 'Bukti pembayaran ditolak')),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentTransactions::route('/')];
    }
}
