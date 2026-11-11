{{-- Template PDF sertifikat (dompdf). Variabel: lihat CertificateService::viewData. --}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $c->number }}</title>
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #1f2937; }
  .page { position: relative; width: 100%; height: 100%; page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  .bg { position: absolute; left: 0; top: 0; width: 100%; height: 100%; z-index: -1; }
  .frame { position: absolute; left: 10mm; top: 10mm; right: 10mm; bottom: 10mm; border: 3px solid {{ $t->accent_color }}; }
  .frame2 { position: absolute; left: 12.5mm; top: 12.5mm; right: 12.5mm; bottom: 12.5mm; border: 1px solid {{ $t->accent_color }}; }
  .bar { position: absolute; left: 0; top: 0; width: 7mm; height: 100%; background: {{ $t->accent_color }}; }
  .content { position: absolute; left: 22mm; right: 22mm; top: 16mm; bottom: 16mm; text-align: center; }
  .issuer { font-size: 11pt; letter-spacing: 1px; color: #4b5563; text-transform: uppercase; }
  .issuer b { color: {{ $t->accent_color }}; }
  .logo { height: 16mm; margin-bottom: 2mm; }
  h1 { margin: 6mm 0 1mm; font-size: 28pt; letter-spacing: 4px; color: {{ $t->accent_color }}; font-weight: 700; }
  .number { font-size: 10pt; color: #6b7280; margin-bottom: 7mm; }
  .sub { font-size: 11pt; color: #4b5563; }
  .name { font-size: 24pt; font-weight: 700; margin: 3mm 0 1mm; color: #111827; }
  .rule { width: 70mm; height: 2px; background: {{ $t->accent_color }}; margin: 0 auto 4mm; }
  .body { font-size: 12pt; line-height: 1.6; max-width: 200mm; margin: 0 auto; }
  .body b { color: {{ $t->accent_color }}; }
  .meta { font-size: 10pt; color: #4b5563; margin-top: 3mm; }
  .predicate { display: inline-block; margin-top: 3mm; padding: 1.5mm 5mm; border: 1px solid {{ $t->accent_color }}; border-radius: 10mm; color: {{ $t->accent_color }}; font-weight: 700; font-size: 10.5pt; }
  .sign { position: absolute; left: 22mm; right: 22mm; bottom: 14mm; }
  .sign table { width: 100%; border-collapse: collapse; }
  .sign td { vertical-align: bottom; text-align: center; font-size: 10pt; }
  .sign .qr { width: 34mm; text-align: left; }
  .sign .qr img { width: 26mm; height: 26mm; }
  .sign .qr small { display: block; font-size: 6.5pt; color: #6b7280; margin-top: 1mm; }
  .sig-img { height: 18mm; }
  .sig-line { width: 60mm; border-top: 1px solid #111827; margin: 1.5mm auto 1mm; }
  .sig-name { font-weight: 700; }
  .sig-title { color: #4b5563; }
  .footer { position: absolute; left: 22mm; right: 22mm; bottom: 6mm; font-size: 7.5pt; color: #6b7280; text-align: center; }
  .watermark { position: absolute; left: 0; top: 40%; width: 100%; text-align: center; font-size: 64pt; color: rgba(220, 38, 38, 0.18); font-weight: 700; letter-spacing: 10px; transform: rotate(-18deg); }
  /* halaman 2 */
  .p2 { position: absolute; left: 22mm; right: 22mm; top: 18mm; bottom: 18mm; }
  .p2 h2 { color: {{ $t->accent_color }}; font-size: 16pt; margin: 0 0 2mm; }
  .p2 .sub2 { font-size: 10pt; color: #4b5563; margin-bottom: 6mm; }
  .p2 table { width: 100%; border-collapse: collapse; font-size: 10.5pt; }
  .p2 th, .p2 td { border: 1px solid #d1d5db; padding: 2mm 3mm; text-align: left; }
  .p2 th { background: #f3f4f6; color: #111827; }
  .p2 .info td { border: none; padding: 1mm 0; }
  .p2 .info td:first-child { width: 45mm; color: #4b5563; }
</style>
</head>
<body>
<div class="page">
  @if($background)<img class="bg" src="{{ $background }}" alt="">@else<div class="bar"></div><div class="frame"></div><div class="frame2"></div>@endif
  @if($preview)<div class="watermark">PRATINJAU</div>@endif
  <div class="content">
    @if($logo)<img class="logo" src="{{ $logo }}" alt=""><br>@endif
    <div class="issuer"><b>{{ $c->issuer_name ?: $t->issuer_name }}</b>@if($t->issuer_title) &middot; {{ $t->issuer_title }}@endif</div>
    <h1>{{ $t->title }}</h1>
    <div class="number">Nomor: {{ $c->number }}</div>
    <div class="sub">{{ $t->subtitle ?: 'Diberikan kepada' }}</div>
    <div class="name">{{ $c->recipient_name }}</div>
    <div class="rule"></div>
    <div class="body">{!! e($body) !!}</div>
    <div class="meta">
      @if($periodLabel)Periode pelatihan: {{ $periodLabel }}@endif
      @if($c->hours) &middot; {{ $c->hours }} jam pelajaran @endif
      @if($c->score !== null) &middot; Nilai: {{ (float) $c->score }}@endif
    </div>
    @if($c->predicate)<div class="predicate">{{ $c->predicate }}</div>@endif
  </div>
  <div class="sign">
    <table>
      <tr>
        <td class="qr">
          <img src="{{ $qr }}" alt="QR verifikasi">
          <small>Pindai untuk verifikasi<br>{{ $verifyUrl }}</small>
        </td>
        @if($t->second_signatory_name)
        <td>
          @if($signature2)<img class="sig-img" src="{{ $signature2 }}" alt=""><br>@else<div style="height:18mm"></div>@endif
          <div class="sig-line"></div>
          <div class="sig-name">{{ $t->second_signatory_name }}</div>
          <div class="sig-title">{{ $t->second_signatory_title }}</div>
        </td>
        @endif
        <td>
          <div style="font-size:9.5pt;color:#4b5563;margin-bottom:1mm">{{ $t->issuer_city ?: 'Batam' }}, {{ $issuedLabel }}</div>
          @if($signature)<img class="sig-img" src="{{ $signature }}" alt=""><br>@else<div style="height:18mm"></div>@endif
          <div class="sig-line"></div>
          <div class="sig-name">{{ $c->signatory_name ?: $t->signatory_name }}</div>
          <div class="sig-title">{{ $c->signatory_title ?: $t->signatory_title }}</div>
        </td>
      </tr>
    </table>
  </div>
  <div class="footer">
    {{ $t->footer_text }}
    @if($validLabel) &middot; Berlaku hingga {{ $validLabel }}@endif
  </div>
</div>
@if(count($competencies) > 0)
<div class="page">
  @if(!$background)<div class="bar"></div><div class="frame"></div>@endif
  @if($preview)<div class="watermark">PRATINJAU</div>@endif
  <div class="p2">
    <h2>Lampiran: {{ $c->type === 'competency' ? 'Unit Kompetensi' : 'Materi Pelatihan' }}</h2>
    <div class="sub2">{{ $c->recipient_name }} &middot; {{ $c->program_title }} &middot; Nomor {{ $c->number }}</div>
    <table class="info" style="margin-bottom:5mm">
      <tr><td>Program</td><td>{{ $c->program_title }}@if($c->class_code) ({{ $c->class_code }})@endif</td></tr>
      @if($periodLabel)<tr><td>Periode</td><td>{{ $periodLabel }}@if($c->city), {{ $c->city }}@endif</td></tr>@endif
      @if($c->hours)<tr><td>Durasi</td><td>{{ $c->hours }} jam pelajaran</td></tr>@endif
      @if($c->score !== null)<tr><td>Nilai</td><td>{{ (float) $c->score }}@if($c->predicate) ({{ $c->predicate }})@endif</td></tr>@endif
      <tr><td>Penerbit</td><td>{{ $c->issuer_name ?: $t->issuer_name }}</td></tr>
    </table>
    <table>
      <thead><tr><th style="width:12mm">No.</th><th>{{ $c->type === 'competency' ? 'Unit kompetensi' : 'Materi' }}</th></tr></thead>
      <tbody>
        @foreach($competencies as $i => $item)
        <tr><td>{{ $i + 1 }}</td><td>{{ $item }}</td></tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div class="footer">{{ $t->issuer_name }} &middot; {{ $verifyUrl }}</div>
</div>
@endif
</body>
</html>
