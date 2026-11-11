<?php

namespace App\Filament\Resources\CertificateTemplates;

use App\Filament\Resources\CertificateTemplates\Pages\CreateCertificateTemplate;
use App\Filament\Resources\CertificateTemplates\Pages\EditCertificateTemplate;
use App\Filament\Resources\CertificateTemplates\Pages\ListCertificateTemplates;
use App\Filament\Support\Fields;
use App\Models\CertificateTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Sertifikat — template (judul, kalimat, penerbit, penandatangan, masa berlaku, format nomor) PRD §5.10. */
class CertificateTemplateResource extends Resource
{
    protected static ?string $model = CertificateTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Sertifikat';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Template sertifikat';

    protected static ?string $modelLabel = 'Template sertifikat';

    protected static ?string $pluralModelLabel = 'Template sertifikat';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('certificates.manage') || $u?->isAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas template')->columns(3)->components([
                TextInput::make('name')->label('Nama template')->required()->maxLength(255),
                Select::make('type')->label('Jenis')->options(CertificateTemplate::TYPES)->default('participation')->required()->native(false)
                    ->helperText('Sertifikat keikutsertaan vs kompetensi (lembaga berwenang) dibedakan jenisnya.'),
                TextInput::make('number_format')->label('Format nomor')->default('PPTI-{TYPE}-{YYYY}{MM}-{SEQ}')->required()->maxLength(80)
                    ->helperText('Placeholder: {TYPE} (PST/KMP/VND), {YYYY}, {YY}, {MM}, {SEQ} (5 digit, urut per jenis per tahun). Hindari garis miring.'),
                Toggle::make('is_default')->label('Bawaan untuk jenis ini')->inline(false),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                TextInput::make('validity_months')->label('Masa berlaku (bulan)')->numeric()->minValue(1)->maxValue(600)->helperText('Kosongkan bila tanpa batas.'),
            ]),
            Section::make('Isi sertifikat')->columns(2)->components([
                TextInput::make('title')->label('Judul')->default('SERTIFIKAT')->required()->maxLength(255),
                TextInput::make('subtitle')->label('Sub-judul')->default('Diberikan kepada')->maxLength(255),
                Textarea::make('body')->label('Kalimat utama')->rows(3)->columnSpanFull()
                    ->helperText('Placeholder: {name} {program} {class_code} {start_date} {end_date} {city} {hours} {score} {issuer} {predicate}.'),
                Textarea::make('footer_text')->label('Catatan kaki')->rows(2)->columnSpanFull(),
                Toggle::make('show_competencies')->label('Cetak lampiran materi/unit kompetensi (halaman 2)')->default(true),
            ]),
            Section::make('Penerbit & penandatangan')->columns(3)->components([
                TextInput::make('issuer_name')->label('Nama penerbit')->default('PPTI - VMES Training Center')->required()->maxLength(255),
                TextInput::make('issuer_title')->label('Keterangan penerbit')->maxLength(255)->placeholder('mis. Lembaga Pelatihan Kerja'),
                TextInput::make('issuer_city')->label('Kota penerbitan')->default('Batam')->maxLength(80),
                TextInput::make('signatory_name')->label('Penandatangan')->maxLength(255),
                TextInput::make('signatory_title')->label('Jabatan')->maxLength(255),
                Fields::image('signature_image', 'certificates/signatures', 800)->label('Tanda tangan (gambar)'),
                TextInput::make('second_signatory_name')->label('Penandatangan kedua')->maxLength(255),
                TextInput::make('second_signatory_title')->label('Jabatan kedua')->maxLength(255),
                Fields::image('second_signature_image', 'certificates/signatures', 800)->label('Tanda tangan kedua'),
            ]),
            Section::make('Tampilan')->columns(3)->components([
                Select::make('orientation')->label('Orientasi')->options(['landscape' => 'Landscape', 'portrait' => 'Portrait'])->default('landscape')->required()->native(false),
                ColorPicker::make('accent_color')->label('Warna aksen')->default('#004B23'),
                Fields::image('logo_image', 'certificates/assets', 800)->label('Logo (opsional)'),
                Fields::image('background_image', 'certificates/assets', 2400)->label('Latar belakang (A4, opsional)')->columnSpanFull()
                    ->helperText('Bila diisi, bingkai bawaan tidak digambar; gunakan desain penuh A4 (3508×2480 px landscape).'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('type')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn ($state) => CertificateTemplate::TYPES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'competency' ? 'warning' : ($state === 'vendor' ? 'info' : 'success')),
                TextColumn::make('number_format')->label('Format nomor'),
                TextColumn::make('signatory_name')->label('Penandatangan')->placeholder('-'),
                TextColumn::make('validity_months')->label('Berlaku (bln)')->placeholder('∞')->alignCenter(),
                IconColumn::make('is_default')->label('Bawaan')->boolean(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('certificates_count')->label('Dipakai')->counts('certificates')->alignCenter(),
            ])
            ->filters([SelectFilter::make('type')->options(CertificateTemplate::TYPES)])
            ->recordActions([
                Action::make('preview')->label('Contoh PDF')->icon('heroicon-o-eye')->url(fn (CertificateTemplate $record) => route('admin.certificate_templates.preview', $record))->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make()->visible(fn (CertificateTemplate $record) => ! $record->certificates()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCertificateTemplates::route('/'),
            'create' => CreateCertificateTemplate::route('/create'),
            'edit' => EditCertificateTemplate::route('/{record}/edit'),
        ];
    }
}
