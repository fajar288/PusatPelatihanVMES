<?php

namespace App\Filament\Resources\Alumni;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Alumni\Pages\ListAlumni;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Certificate;
use App\Models\Member;
use App\Models\TrainingPackage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Alumni: member berstatus alumni (status dihitung MemberService). Resource terpisah di atas model Member karena
 * kolom, filter (tahun lulus), dan ekspornya berbeda dari daftar Peserta; detail tetap di halaman Peserta.
 */
class AlumniResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Certificates;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Alumni';

    protected static ?string $modelLabel = 'Alumni';

    protected static ?string $pluralModelLabel = 'Alumni';

    protected static ?string $slug = 'alumni';

    public static function canViewAny(): bool
    {
        return MemberResource::canAccessMembers();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Sertifikat aktif: terbit dan belum lewat masa berlaku (sama dengan Certificate::isValid). */
    public static function activeCertificates(Builder $query): Builder
    {
        return $query->where('status', 'issued')->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'alumni')
            ->with(['user', 'enrollments' => fn ($q) => $q->where('status', 'completed')->with('schedule.package')])
            ->withCount(['certificates as active_certificates_count' => fn (Builder $q) => self::activeCertificates($q)]);
    }

    /** @return string[] judul program yang diselesaikan (lulus) */
    public static function completedPrograms(Member $member): array
    {
        return $member->enrollments->where('status', 'completed')
            ->map(fn ($e) => $e->schedule?->package?->title ?? $e->schedule?->title)
            ->filter()->unique()->values()->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('alumni_since', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Nama')->searchable()->sortable()
                    ->description(fn (Member $record) => $record->member_number),
                TextColumn::make('programs')->label('Program yang diselesaikan')->state(fn (Member $record) => self::completedPrograms($record))
                    ->listWithLineBreaks()->limitList(3)->expandableLimitedList()->placeholder('-'),
                TextColumn::make('alumni_since')->label('Tanggal lulus')->date('d M Y')->sortable()->placeholder('-'),
                TextColumn::make('active_certificates_count')->label('Sertifikat aktif')->alignCenter()->sortable(),
                TextColumn::make('company')->label('Perusahaan')->searchable()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('year')->label('Tahun lulus')
                    ->options(fn () => Member::query()->where('status', 'alumni')->whereNotNull('alumni_since')->pluck('alumni_since')
                        ->map(fn ($d) => substr((string) $d, 0, 4))->unique()->sortDesc()->mapWithKeys(fn ($y) => [$y => $y])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $year) => $q->whereBetween('alumni_since', [$year.'-01-01', $year.'-12-31']))),
                SelectFilter::make('program')->label('Program')->searchable()
                    ->options(fn () => TrainingPackage::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q->whereHas('enrollments', fn (Builder $e) => $e->where('status', 'completed')->whereHas('schedule', fn (Builder $s) => $s->where('package_id', $id))))),
            ])
            ->recordUrl(null)
            ->recordActions([
                Action::make('certificates')->label('Sertifikat')->icon(Heroicon::OutlinedDocumentCheck)
                    ->modalHeading(fn (Member $record) => 'Sertifikat '.($record->user?->name ?? $record->member_number))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->schema(fn (Member $record) => [
                        RepeatableEntry::make('certificates')->hiddenLabel()->placeholder('Belum ada sertifikat.')
                            ->state(fn () => $record->certificates()->latest('id')->get()->map(fn (Certificate $c) => [
                                'number' => $c->number ?? '(draf)',
                                'program' => $c->program_title,
                                'status' => $c->statusLabel().($c->isExpired() ? ' (kedaluwarsa)' : ''),
                                'issued_at' => $c->issued_at?->format('d M Y') ?? '-',
                            ])->all())
                            ->columns(['default' => 1, 'md' => 4])
                            ->schema([
                                TextEntry::make('number')->label('Nomor')->copyable(),
                                TextEntry::make('program')->label('Program'),
                                TextEntry::make('status')->label('Status')->badge(),
                                TextEntry::make('issued_at')->label('Tanggal'),
                            ]),
                    ]),
                Action::make('history')->label('Riwayat training')->icon(Heroicon::OutlinedClock)
                    ->url(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlumni::route('/'),
        ];
    }
}
