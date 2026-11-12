<?php

namespace App\Filament\Support;

use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\PaymentTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/** Bantuan bersama menu keuangan (Roadmap 5C): izin, format rupiah, field formulir verifikasi. */
final class FinanceFields
{
    public static function canManage(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('payments.verify') || $u?->isSuperAdmin());
    }

    public static function canApproveRefund(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('payments.refund.approve') || $u?->isSuperAdmin());
    }

    public static function rupiah(mixed $v): string
    {
        return $v === null ? '-' : 'Rp '.number_format((float) $v, 0, ',', '.');
    }

    public static function invoiceColor(?string $state): string
    {
        return match ($state) {
            'paid' => 'success', 'pending' => 'warning', 'failed', 'expired' => 'danger', 'refunded' => 'info', default => 'gray',
        };
    }

    public static function transactionColor(?string $state): string
    {
        return match ($state) {
            'paid' => 'success', 'pending' => 'warning', 'failed' => 'danger', default => 'gray',
        };
    }

    /** Formulir verifikasi transaksi (nominal diterima, tanggal, nyatakan lunas bila kurang). @return list<mixed> */
    public static function verifySchema(): array
    {
        return [
            TextInput::make('amount_received')->label('Nominal diterima di rekening (Rp)')->numeric()->required()->minValue(1)
                ->helperText('Cocokkan dengan mutasi rekening. Selisih dengan sisa tagihan dicatat otomatis.'),
            DatePicker::make('transfer_date')->label('Tanggal dana masuk')->native(false)->maxDate(today()),
            Toggle::make('settle')->label('Nyatakan lunas walau kurang bayar')
                ->helperText('Hanya bila kekurangan disetujui (mis. potongan biaya transfer). Wajib isi catatan.'),
            Textarea::make('note')->label('Catatan finance')->rows(2)->requiredIf('settle', true),
        ];
    }

    /** @return list<mixed> */
    public static function recordPaymentSchema(Invoice $invoice): array
    {
        return [
            TextInput::make('amount')->label('Nominal diterima (Rp)')->numeric()->required()->minValue(1)->default($invoice->outstanding()),
            Select::make('method')->label('Metode')->options(PaymentTransaction::METHODS)->default('bank_transfer')->required(),
            Select::make('payment_account_id')->label('Rekening tujuan')->options(PaymentAccount::query()->orderBy('sort')->get()->mapWithKeys(fn (PaymentAccount $a) => [$a->id => $a->summary()])->all()),
            DatePicker::make('transfer_date')->label('Tanggal dana masuk')->native(false)->default(today())->maxDate(today())->required(),
            TextInput::make('payer_name')->label('Nama pengirim')->default($invoice->bill_to_name)->maxLength(120),
            Toggle::make('settle')->label('Nyatakan lunas walau kurang bayar'),
            Textarea::make('note')->label('Catatan / referensi mutasi')->rows(2)->requiredIf('settle', true),
        ];
    }
}
