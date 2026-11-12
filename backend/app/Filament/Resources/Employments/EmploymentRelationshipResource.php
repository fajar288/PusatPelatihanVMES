<?php

namespace App\Filament\Resources\Employments;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Employments\Pages\ManageEmployments;
use App\Filament\Support\EnterpriseFields;
use App\Models\EmploymentRelationship;
use App\Models\Member;
use App\Models\Organization;
use App\Services\EmploymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Hubungan kerja Member ↔ Organisasi (Roadmap 5 §20.6). Dibuat & diakhiri lewat EmploymentService (diaudit).
 * Mengakhiri (resign/pindah) tidak menghapus member, enrollment, atau sertifikat.
 */
class EmploymentRelationshipResource extends Resource
{
    protected static ?string $model = EmploymentRelationship::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Corporate;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Employment';

    protected static ?string $slug = 'employments';

    protected static ?string $modelLabel = 'Hubungan kerja';

    protected static ?string $pluralModelLabel = 'Hubungan kerja';

    public static function canViewAny(): bool
    {
        return EnterpriseFields::canManage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            EnterpriseFields::member(),
            EnterpriseFields::organization(),
            EnterpriseFields::unit(),
            TextInput::make('employee_id')->label('Employee ID')->maxLength(60),
            TextInput::make('position')->label('Posisi / jabatan')->maxLength(255),
            DatePicker::make('start_date')->label('Mulai bekerja')->native(false),
            Select::make('source')->label('Sumber data')->options(EmploymentRelationship::SOURCES)->default('admin')->required()->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable(),
                TextColumn::make('member.user.name')->label('Member')->searchable()->description(fn (EmploymentRelationship $record) => $record->member?->member_number),
                TextColumn::make('organization.legal_name')->label('Organisasi')->searchable(),
                TextColumn::make('employee_id')->label('Employee ID')->searchable()->placeholder('-'),
                TextColumn::make('unit.name')->label('Unit')->placeholder('-')->description(fn (EmploymentRelationship $record) => $record->position),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => EmploymentRelationship::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray')
                    ->description(fn (EmploymentRelationship $record) => $record->end_reason ? (EmploymentRelationship::END_REASONS[$record->end_reason] ?? $record->end_reason).' · '.$record->end_date?->format('d M Y') : null),
                TextColumn::make('start_date')->label('Mulai')->date('d M Y')->placeholder('-')->toggleable(),
                TextColumn::make('source')->label('Sumber')->formatStateUsing(fn ($state) => EmploymentRelationship::SOURCES[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(EmploymentRelationship::STATUSES),
                SelectFilter::make('organization_id')->label('Organisasi')->relationship('organization', 'legal_name')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('end')->label('Akhiri')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('warning')
                    ->visible(fn (EmploymentRelationship $record) => $record->isActive())
                    ->modalDescription('Employment menjadi tidak aktif dan akses perusahaan orang ini di organisasi tsb dicabut. Member, enrollment, dan sertifikat tetap.')
                    ->schema([
                        Select::make('reason')->label('Alasan')->options(EmploymentRelationship::END_REASONS)->default('resigned')->required()->native(false),
                        DatePicker::make('end_date')->label('Tanggal berakhir')->default(today())->required()->native(false),
                    ])
                    ->action(fn (array $data, EmploymentRelationship $record, Action $action) => EnterpriseFields::attempt($action, fn () => app(EmploymentService::class)->end($record, $data['end_date'], $data['reason'], auth()->user()), 'Hubungan kerja diakhiri')),
            ]);
    }

    /** Dipanggil dari halaman: buat lewat service (validasi duplikat aktif & employee ID, audit). */
    public static function createViaService(array $data): EmploymentRelationship
    {
        return app(EmploymentService::class)->start(
            Member::query()->findOrFail($data['member_id']),
            Organization::query()->findOrFail($data['organization_id']),
            $data,
            $data['source'] ?? 'admin',
            auth()->user(),
        );
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployments::route('/')];
    }
}
