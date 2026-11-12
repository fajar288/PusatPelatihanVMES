<?php

namespace App\Filament\Resources\PaymentRefunds;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PaymentRefunds\Pages\ListPaymentRefunds;
use App\Filament\Support\EnterpriseFields;
use App\Filament\Support\FinanceFields;
use App\Models\PaymentRefund;
use App\Services\RefundService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Refund manual (PRD Roadmap 5 §5.1): diajukan finance dari halaman invoice → disetujui/ditolak orang lain berizin
 * `payments.refund.approve` → dicatat selesai dengan referensi transfer balik. Dana dikembalikan di luar sistem.
 */
class PaymentRefundResource extends Resource
{
    protected static ?string $model = PaymentRefund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Refund';

    protected static ?string $modelLabel = 'Refund';

    protected static ?string $pluralModelLabel = 'Refund';

    protected static ?string $slug = 'payment-refunds';

    public static function canViewAny(): bool
    {
        return FinanceFields::canManage() || FinanceFields::canApproveRefund();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = PaymentRefund::query()->whereIn('status', ['requested', 'approved'])->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['invoice', 'requester', 'decider', 'completer']))
            ->columns(self::columns(true))
            ->filters([SelectFilter::make('status')->options(PaymentRefund::STATUSES)])
            ->recordActions(self::workflowActions());
    }

    /** @return list<TextColumn> */
    public static function columns(bool $withInvoice): array
    {
        return array_values(array_filter([
            TextColumn::make('code')->label('Kode')->searchable(),
            $withInvoice ? TextColumn::make('invoice.invoice_number')->label('Invoice')->searchable()->description(fn (PaymentRefund $record) => $record->invoice?->bill_to_name)
                ->url(fn (PaymentRefund $record) => $record->invoice ? InvoiceResource::getUrl('view', ['record' => $record->invoice]) : null) : null,
            TextColumn::make('amount')->label('Nominal')->money('IDR', locale: 'id'),
            TextColumn::make('reason')->label('Alasan')->limit(50)->tooltip(fn (PaymentRefund $record) => $record->reason)->wrap(),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => PaymentRefund::STATUSES[$state] ?? $state)
                ->color(fn ($state) => match ($state) {
                    'completed' => 'success', 'approved' => 'info', 'requested' => 'warning', 'rejected' => 'danger', default => 'gray'
                }),
            TextColumn::make('requester.name')->label('Pengaju')->description(fn (PaymentRefund $record) => $record->requested_at?->format('d M Y H:i')),
            TextColumn::make('decider.name')->label('Penyetuju')->placeholder('-')->description(fn (PaymentRefund $record) => $record->decided_at?->format('d M Y H:i')),
            TextColumn::make('transfer_reference')->label('Referensi transfer')->placeholder('-')->description(fn (PaymentRefund $record) => $record->transferred_on?->format('d M Y')),
        ]));
    }

    /** @return list<Action> */
    public static function workflowActions(): array
    {
        $svc = fn () => app(RefundService::class);

        return [
            Action::make('approve')->label('Setujui')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (PaymentRefund $record) => $record->status === 'requested' && FinanceFields::canApproveRefund() && $record->requested_by !== auth()->id())
                ->schema([Textarea::make('note')->label('Catatan')->rows(2)])
                ->action(fn (array $data, PaymentRefund $record, Action $action) => EnterpriseFields::attempt($action, fn () => $svc()->approve($record, auth()->user(), $data['note'] ?? null), 'Refund disetujui')),
            Action::make('reject')->label('Tolak')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (PaymentRefund $record) => $record->status === 'requested' && FinanceFields::canApproveRefund() && $record->requested_by !== auth()->id())
                ->schema([Textarea::make('note')->label('Alasan penolakan')->required()->rows(2)])
                ->action(fn (array $data, PaymentRefund $record, Action $action) => EnterpriseFields::attempt($action, fn () => $svc()->reject($record, auth()->user(), $data['note']), 'Refund ditolak')),
            Action::make('complete')->label('Catat transfer balik')->icon('heroicon-o-banknotes')->color('primary')
                ->visible(fn (PaymentRefund $record) => $record->status === 'approved' && FinanceFields::canManage())
                ->modalDescription('Dana dikembalikan di luar sistem (transfer bank). Catat referensinya di sini.')
                ->schema([
                    TextInput::make('reference')->label('Referensi transfer balik')->required()->maxLength(255),
                    DatePicker::make('transferred_on')->label('Tanggal transfer')->required()->native(false)->default(today())->maxDate(today()),
                ])
                ->action(fn (array $data, PaymentRefund $record, Action $action) => EnterpriseFields::attempt($action, fn () => $svc()->complete($record, auth()->user(), $data['reference'], $data['transferred_on']), 'Refund dicatat selesai')),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentRefunds::route('/')];
    }
}
