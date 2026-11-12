<?php

namespace App\Filament\Resources\Reconciliation;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Reconciliation\Pages\ListStatementLines;
use App\Filament\Support\EnterpriseFields;
use App\Filament\Support\FinanceFields;
use App\Models\BankStatementLine;
use App\Models\PaymentTransaction;
use App\Services\ReconciliationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Rekonsiliasi (Roadmap 5C): mutasi rekening (CSV/XLSX, opsional) dicocokkan dengan transaksi pembayaran. Otomatis bila
 * kandidat tepat satu (nominal sama ±3 hari, atau nomor invoice/transaksi di keterangan); sisanya manual. Selisih nominal
 * transaksi terhadap invoice terlihat di menu Verifikasi pembayaran (filter "Ada selisih").
 */
class ReconciliationResource extends Resource
{
    protected static ?string $model = BankStatementLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Rekonsiliasi';

    protected static ?string $modelLabel = 'Baris mutasi';

    protected static ?string $pluralModelLabel = 'Rekonsiliasi mutasi rekening';

    protected static ?string $slug = 'reconciliation';

    public static function canViewAny(): bool
    {
        return FinanceFields::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('posted_on', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['transaction.invoice', 'import', 'matcher']))
            ->emptyStateHeading('Belum ada mutasi rekening')
            ->emptyStateDescription('Unggah mutasi rekening (CSV/XLSX dari internet banking) untuk dicocokkan dengan transaksi pembayaran. Opsional.')
            ->columns([
                TextColumn::make('posted_on')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('description')->label('Keterangan')->wrap()->limit(70)->searchable()
                    ->description(fn (BankStatementLine $record) => $record->reference ? 'Ref '.$record->reference : null),
                TextColumn::make('amount')->label('Kredit')->money('IDR', locale: 'id')->sortable(),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => BankStatementLine::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'matched' => 'success', 'unmatched' => 'warning', default => 'gray'
                    })
                    ->description(fn (BankStatementLine $record) => $record->match_method === 'auto' ? 'otomatis' : ($record->matcher ? 'oleh '.$record->matcher->name : null)),
                TextColumn::make('transaction.code')->label('Transaksi')->placeholder('-')
                    ->description(fn (BankStatementLine $record) => self::matchNote($record))
                    ->url(fn (BankStatementLine $record) => $record->transaction?->invoice ? InvoiceResource::getUrl('view', ['record' => $record->transaction->invoice]) : null),
                TextColumn::make('import.file_name')->label('Berkas')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('status')->options(BankStatementLine::STATUSES)->default('unmatched')])
            ->recordActions([
                Action::make('match')->label('Cocokkan')->icon('heroicon-o-link')->color('success')
                    ->visible(fn (BankStatementLine $record) => $record->status === 'unmatched')
                    ->schema(fn (BankStatementLine $record) => [
                        Select::make('payment_transaction_id')->label('Transaksi pembayaran')->required()->searchable()
                            ->options(fn () => self::options(app(ReconciliationService::class)->candidates($record)->limit(30)->get()))
                            ->getSearchResultsUsing(fn (string $search) => self::options(PaymentTransaction::query()->whereIn('status', ['pending', 'paid'])->whereDoesntHave('statementLine')
                                ->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('payer_name', 'like', "%{$search}%")
                                    ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'like', "%{$search}%")->orWhere('bill_to_name', 'like', "%{$search}%")))
                                ->with('invoice')->limit(30)->get()))
                            // validasi: hanya transaksi pending/terverifikasi yang belum tertaut mutasi lain
                            ->getOptionLabelUsing(fn ($value) => self::options(PaymentTransaction::query()->whereKey($value)->whereIn('status', ['pending', 'paid'])
                                ->whereDoesntHave('statementLine')->with('invoice')->get())[$value] ?? null)
                            ->helperText('Daftar awal = kandidat dengan nominal sama (±'.ReconciliationService::DATE_TOLERANCE.' hari). Cari kode transaksi, nomor invoice, atau nama.'),
                    ])
                    ->action(fn (array $data, BankStatementLine $record, Action $action) => EnterpriseFields::attempt($action,
                        fn () => app(ReconciliationService::class)->match($record, PaymentTransaction::query()->findOrFail($data['payment_transaction_id']), auth()->user()), 'Mutasi dicocokkan')),
                Action::make('unmatch')->label('Lepas')->icon('heroicon-o-link-slash')->color('gray')->requiresConfirmation()
                    ->visible(fn (BankStatementLine $record) => $record->status === 'matched')
                    ->action(fn (BankStatementLine $record) => app(ReconciliationService::class)->unmatch($record, auth()->user())),
                Action::make('ignore')->label('Abaikan')->icon('heroicon-o-eye-slash')->color('gray')
                    ->visible(fn (BankStatementLine $record) => $record->status === 'unmatched')
                    ->modalDescription('Untuk dana masuk yang bukan pembayaran training (mis. bunga, setoran internal).')
                    ->schema([Textarea::make('note')->label('Catatan')->rows(2)])
                    ->action(fn (array $data, BankStatementLine $record) => app(ReconciliationService::class)->ignore($record, auth()->user(), $data['note'] ?? null)),
            ]);
    }

    /** @return array<int, string> */
    private static function options($txs): array
    {
        return $txs->mapWithKeys(fn (PaymentTransaction $t) => [$t->id => $t->code.' · '.$t->invoice?->invoice_number.' · '.$t->invoice?->bill_to_name.' · '
            .FinanceFields::rupiah($t->amount_received ?? $t->amount).' · '.($t->transfer_date?->format('d M Y') ?? '-').' · '.$t->statusLabel()])->all();
    }

    private static function matchNote(BankStatementLine $line): ?string
    {
        $t = $line->transaction;
        if (! $t) {
            return null;
        }
        $diff = round((float) $line->amount - (float) ($t->amount_received ?? $t->amount), 2);

        return $t->invoice?->invoice_number.' · '.$t->statusLabel().($diff != 0 ? ' · selisih '.($diff > 0 ? '+' : '−').FinanceFields::rupiah(abs($diff)) : '');
    }

    public static function getPages(): array
    {
        return ['index' => ListStatementLines::route('/')];
    }
}
