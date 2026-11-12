<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Pengaturan keuangan (SiteSetting `finance`). Keputusan terbuka Roadmap 5: tarif pajak invoice bawaan 0% dan baris pajak
 * disembunyikan bila 0. Diubah lewat admin Keuangan → Pengaturan keuangan.
 */
final class FinanceSettings
{
    public const DEFAULTS = [
        'tax_rate' => 0.0,
        'tax_label' => 'PPN',
        'due_days' => 3,
        'corporate_due_days' => 14,
        'invoice_notes' => null,
    ];

    /** @return array{tax_rate: float, tax_label: string, due_days: int, corporate_due_days: int, invoice_notes: ?string} */
    public static function all(): array
    {
        $v = array_merge(self::DEFAULTS, array_filter((array) SiteSetting::get('finance', []), fn ($x) => $x !== null && $x !== ''));

        return [
            'tax_rate' => max(0.0, min(100.0, (float) $v['tax_rate'])),
            'tax_label' => (string) ($v['tax_label'] ?: 'PPN'),
            'due_days' => max(1, (int) $v['due_days']),
            'corporate_due_days' => max(1, (int) $v['corporate_due_days']),
            'invoice_notes' => $v['invoice_notes'] ? (string) $v['invoice_notes'] : null,
        ];
    }

    public static function taxRate(): float
    {
        return self::all()['tax_rate'];
    }

    /** @param  array<string, mixed>  $values */
    public static function save(array $values): void
    {
        SiteSetting::set('finance', array_intersect_key($values, self::DEFAULTS) + self::DEFAULTS, 'finance');
    }
}
