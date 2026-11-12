{{-- Invoice PDF (dompdf). Variabel: $inv (Invoice + items, transactions, refunds), $site, $logo, $accounts, $rp. --}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $inv->invoice_number }}</title>
<style>
  @page { margin: 16mm 16mm 18mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; }
  table { width: 100%; border-collapse: collapse; }
  .head td { vertical-align: top; }
  .logo { height: 15mm; }
  .co { font-size: 8.5pt; color: #4b5563; line-height: 1.45; }
  .co b { color: #111827; font-size: 10pt; }
  h1 { font-size: 20pt; margin: 0; color: #004B23; letter-spacing: 2px; text-align: right; }
  .num { text-align: right; font-size: 10pt; margin-top: 1mm; }
  .badge { display: inline-block; padding: 1mm 3mm; border-radius: 3mm; font-size: 8pt; font-weight: bold; color: #fff; background: #6b7280; }
  .badge.paid { background: #15803d; } .badge.pending { background: #b45309; } .badge.failed, .badge.expired { background: #b91c1c; } .badge.refunded { background: #4b5563; }
  .meta { margin-top: 7mm; }
  .meta td { vertical-align: top; width: 50%; }
  .label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; margin-bottom: 1mm; }
  .kv td { padding: .6mm 0; } .kv td:first-child { color: #6b7280; width: 32mm; }
  .items { margin-top: 7mm; }
  .items th { background: #004B23; color: #fff; font-size: 8.5pt; text-align: left; padding: 2mm; }
  .items td { padding: 2mm; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  .r { text-align: right; } .c { text-align: center; }
  .totals { width: 48%; margin-left: 52%; margin-top: 3mm; }
  .totals td { padding: 1.2mm 2mm; } .totals td:last-child { text-align: right; }
  .totals .grand td { border-top: 2px solid #004B23; font-weight: bold; font-size: 11pt; }
  .muted { color: #6b7280; }
  .box { margin-top: 6mm; padding: 3mm; border: 1px solid #e5e7eb; border-radius: 2mm; }
  .box h3 { margin: 0 0 2mm; font-size: 9.5pt; }
  .small { font-size: 8pt; }
  .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
<table class="head">
  <tr>
    <td style="width:58%">
      @if ($logo)<img class="logo" src="{{ $logo }}" alt=""><br>@endif
      <div class="co"><b>{{ $site['company'] ?? 'PT. PPTI - VMES Training Center' }}</b><br>{{ $site['address'] ?? '' }}@if (! empty($site['waDisplay']))<br>WhatsApp {{ $site['waDisplay'] }}@endif</div>
    </td>
    <td>
      <h1>INVOICE</h1>
      <div class="num"><b>{{ $inv->invoice_number }}</b></div>
      <div class="num"><span class="badge {{ $inv->status }}">{{ strtoupper($inv->statusLabel()) }}</span></div>
    </td>
  </tr>
</table>

<table class="meta">
  <tr>
    <td>
      <div class="label">Ditagihkan kepada</div>
      <b>{{ $inv->bill_to_name }}</b><br>
      @if ($inv->bill_to_company && $inv->bill_to_company !== $inv->bill_to_name){{ $inv->bill_to_company }}<br>@endif
      @if ($inv->bill_to_address){!! nl2br(e($inv->bill_to_address)) !!}<br>@endif
      @if ($inv->bill_to_email){{ $inv->bill_to_email }}<br>@endif
      @if ($inv->bill_to_tax_id)NPWP {{ $inv->bill_to_tax_id }}@endif
    </td>
    <td>
      <table class="kv">
        <tr><td>Tanggal terbit</td><td>{{ $date($inv->issued_at ?? $inv->created_at) }}</td></tr>
        <tr><td>Jatuh tempo</td><td>{{ $date($inv->due_date) ?? '-' }}</td></tr>
        <tr><td>Referensi</td><td>{{ $inv->sourceLabel() }}</td></tr>
        @if ($inv->installment_total > 1)<tr><td>Termin</td><td>{{ $inv->installment_no }} dari {{ $inv->installment_total }}</td></tr>@endif
        @if ($inv->paid_at)<tr><td>Lunas</td><td>{{ $date($inv->paid_at) }}</td></tr>@endif
      </table>
    </td>
  </tr>
</table>

<table class="items">
  <thead><tr><th style="width:6%">No</th><th>Deskripsi</th><th class="c" style="width:9%">Qty</th><th class="r" style="width:20%">Harga satuan</th><th class="r" style="width:20%">Jumlah</th></tr></thead>
  <tbody>
  @foreach ($inv->items as $i => $item)
    <tr><td>{{ $i + 1 }}</td><td>{{ $item->description }}</td><td class="c">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, ',', '.'), '0'), ',') }}</td><td class="r">{{ $rp($item->unit_price) }}</td><td class="r">{{ $rp($item->amount) }}</td></tr>
  @endforeach
  </tbody>
</table>

<table class="totals">
  <tr><td>Subtotal</td><td>{{ $rp($inv->subtotal) }}</td></tr>
  @if ((float) $inv->discount > 0)<tr><td>Diskon{{ $inv->discount_note ? ' ('.$inv->discount_note.')' : '' }}</td><td>− {{ $rp($inv->discount) }}</td></tr>@endif
  @if ((float) $inv->tax_rate > 0 || (float) $inv->tax_amount > 0)<tr><td>{{ $inv->tax_label ?: 'Pajak' }} {{ rtrim(rtrim(number_format((float) $inv->tax_rate, 2, ',', '.'), '0'), ',') }}%</td><td>{{ $rp($inv->tax_amount) }}</td></tr>@endif
  <tr class="grand"><td>Total</td><td>{{ $rp($inv->total) }}</td></tr>
  @if ((float) $inv->amount_paid > 0)<tr><td class="muted">Terbayar</td><td class="muted">{{ $rp($inv->amount_paid) }}</td></tr>@endif
  @if ((float) $inv->amount_refunded > 0)<tr><td class="muted">Dikembalikan</td><td class="muted">{{ $rp($inv->amount_refunded) }}</td></tr>@endif
  @if (in_array($inv->status, ['pending', 'failed', 'expired'], true))<tr><td><b>Sisa tagihan</b></td><td><b>{{ $rp($inv->outstanding()) }}</b></td></tr>@endif
</table>

@if (in_array($inv->status, ['pending', 'failed'], true) && $accounts->isNotEmpty())
<div class="box">
  <h3>Pembayaran</h3>
  <div class="small">Transfer ke salah satu rekening resmi berikut, lalu unggah bukti transfer di portal. Status invoice berubah setelah diverifikasi tim finance VMES.</div>
  <table class="kv small" style="margin-top:2mm">
    @foreach ($accounts as $a)<tr><td>{{ $a->bank_name }}{{ $a->type === 'virtual_account' ? ' (VA)' : '' }}</td><td><b>{{ $a->account_number }}</b> a.n. {{ $a->account_name }}</td></tr>@endforeach
  </table>
  <div class="small muted" style="margin-top:2mm">Cantumkan nomor invoice {{ $inv->invoice_number }} pada berita transfer.</div>
</div>
@endif

@if ($inv->notes)
<div class="box small"><h3>Catatan</h3>{!! nl2br(e($inv->notes)) !!}</div>
@endif

@php($paidTx = $inv->transactions->where('status', 'paid'))
@if ($paidTx->isNotEmpty())
<div class="box small">
  <h3>Riwayat pembayaran</h3>
  <table class="kv">
    @foreach ($paidTx as $t)<tr><td>{{ $t->code }}</td><td>{{ $date($t->transfer_date ?? $t->verified_at) }} · {{ $t->methodLabel() }} · {{ $rp($t->amount_received ?? $t->amount) }}</td></tr>@endforeach
  </table>
  @if ($inv->settlement_note)<div class="muted" style="margin-top:1mm">{!! nl2br(e($inv->settlement_note)) !!}</div>@endif
</div>
@endif

<div class="foot">Dokumen ini dibuat otomatis oleh ITMS PPTI pada {{ $date(now()) }} dan sah tanpa tanda tangan.</div>
</body>
</html>
