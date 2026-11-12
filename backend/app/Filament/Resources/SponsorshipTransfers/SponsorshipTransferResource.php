<?php

namespace App\Filament\Resources\SponsorshipTransfers;

use App\Filament\NavigationGroup;
use App\Filament\Resources\SponsorshipTransfers\Pages\ManageSponsorshipTransfers;
use App\Filament\Support\EnterpriseFields;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\SponsorshipTransfer;
use App\Services\SponsorshipService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Transfer sponsor enrollment (Roadmap 5 §20.20): permintaan → otorisasi (izin sponsorship.approve, orang lain) →
 * berlaku per tanggal efektif. Catatan final tidak bisa diubah/dihapus; setiap langkah diaudit.
 */
class SponsorshipTransferResource extends Resource
{
    protected static ?string $model = SponsorshipTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Corporate;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Transfer sponsor';

    protected static ?string $modelLabel = 'Transfer sponsor';

    protected static ?string $pluralModelLabel = 'Transfer sponsor';

    public static function canViewAny(): bool
    {
        return EnterpriseFields::canManage();
    }

    public static function canApprove(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('sponsorship.approve') || $u?->isSuperAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('enrollment_id')->label('Enrollment')->searchable()->required()->columnSpanFull()
                ->getSearchResultsUsing(fn (string $search) => Enrollment::query()->with(['schedule:id,code', 'sponsor:id,legal_name'])
                    ->where(fn ($q) => $q->where('participant_name', 'like', "%{$search}%")->orWhere('participant_email', 'like', "%{$search}%")
                        ->orWhereHas('schedule', fn ($s) => $s->where('code', 'like', "%{$search}%")))
                    ->limit(20)->get()->mapWithKeys(fn (Enrollment $e) => [$e->id => self::enrollmentLabel($e)])->all())
                ->getOptionLabelUsing(fn ($value) => ($e = Enrollment::query()->find($value)) ? self::enrollmentLabel($e) : null),
            EnterpriseFields::organization('to_organization_id', false)->label('Sponsor baru')->helperText('Kosongkan bila enrollment menjadi mandiri (self-funded).'),
            EnterpriseFields::unit('to_unit_id', 'to_organization_id'),
            DatePicker::make('effective_date')->label('Tanggal efektif')->default(today())->required()->native(false),
            TextInput::make('authorization_reference')->label('Referensi otorisasi')->placeholder('No. memo / email persetujuan')->maxLength(255),
            Textarea::make('reason')->label('Alasan')->required()->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['enrollment.schedule', 'fromOrganization', 'toOrganization', 'requester', 'decider']))
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable(),
                TextColumn::make('enrollment.participant_name')->label('Peserta')->description(fn (SponsorshipTransfer $record) => $record->enrollment?->schedule?->code),
                TextColumn::make('fromOrganization.legal_name')->label('Dari')->placeholder('Mandiri (SELF)'),
                TextColumn::make('toOrganization.legal_name')->label('Ke')->placeholder('Mandiri (SELF)'),
                TextColumn::make('effective_date')->label('Efektif')->date('d M Y'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => SponsorshipTransfer::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'applied' => 'success', 'approved' => 'info', 'requested' => 'warning', default => 'gray'
                    }),
                TextColumn::make('requester.name')->label('Peminta')->description(fn (SponsorshipTransfer $record) => $record->decider ? 'Diputuskan: '.$record->decider->name : null)->toggleable(),
                TextColumn::make('reason')->label('Alasan')->limit(50)->tooltip(fn (SponsorshipTransfer $record) => $record->reason)->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(SponsorshipTransfer::STATUSES)])
            ->recordActions([
                Action::make('approve')->label('Setujui')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (SponsorshipTransfer $record) => $record->status === 'requested' && self::canApprove())
                    ->schema([Textarea::make('note')->label('Catatan')->rows(2)])
                    ->action(function (array $data, SponsorshipTransfer $record, Action $action): void {
                        $t = EnterpriseFields::attempt($action, fn () => app(SponsorshipService::class)->approve($record, auth()->user(), $data['note'] ?? null));
                        Notification::make()->title($t->status === 'applied' ? 'Disetujui & diterapkan' : 'Disetujui — berlaku pada '.$t->effective_date->format('d M Y'))->success()->send();
                    }),
                Action::make('reject')->label('Tolak')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (SponsorshipTransfer $record) => $record->status === 'requested' && self::canApprove())
                    ->schema([Textarea::make('note')->label('Alasan')->required()->rows(2)])
                    ->action(fn (array $data, SponsorshipTransfer $record, Action $action) => EnterpriseFields::attempt($action, fn () => app(SponsorshipService::class)->reject($record, auth()->user(), $data['note']), 'Transfer ditolak')),
                Action::make('cancel')->label('Batalkan')->icon('heroicon-o-minus-circle')->color('gray')
                    ->visible(fn (SponsorshipTransfer $record) => in_array($record->status, ['requested', 'approved'], true))
                    ->schema([Textarea::make('note')->label('Alasan')->required()->rows(2)])
                    ->action(fn (array $data, SponsorshipTransfer $record, Action $action) => EnterpriseFields::attempt($action, fn () => app(SponsorshipService::class)->cancel($record, auth()->user(), $data['note']), 'Transfer dibatalkan')),
            ]);
    }

    public static function createViaService(array $data): SponsorshipTransfer
    {
        return app(SponsorshipService::class)->request(
            Enrollment::query()->findOrFail($data['enrollment_id']),
            Organization::query()->find($data['to_organization_id'] ?? null),
            $data['effective_date'],
            $data['reason'],
            $data['authorization_reference'] ?? null,
            OrganizationUnit::query()->find($data['to_unit_id'] ?? null),
            auth()->user(),
        );
    }

    private static function enrollmentLabel(Enrollment $e): string
    {
        return '#'.$e->id.' '.$e->participant_name.' — '.($e->schedule?->code ?? '?').' — '.($e->sponsor?->legal_name ?? 'Mandiri');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSponsorshipTransfers::route('/')];
    }
}
