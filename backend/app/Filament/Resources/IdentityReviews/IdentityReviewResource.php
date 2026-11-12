<?php

namespace App\Filament\Resources\IdentityReviews;

use App\Filament\NavigationGroup;
use App\Filament\Resources\IdentityReviews\Pages\ManageIdentityReviews;
use App\Filament\Support\EnterpriseFields;
use App\Models\Enrollment;
use App\Models\IdentityReview;
use App\Models\Member;
use App\Services\IdentityMatchingService;
use App\Support\Enterprise\IdentityMatch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Review identitas (Roadmap 5 §20.5): peserta yang tidak bisa dicocokkan secara yakin. Admin memutuskan:
 * tautkan ke member yang ada, buat akun/member baru, cocokkan ulang, atau abaikan. Tidak ada akun yang dibuat otomatis.
 */
class IdentityReviewResource extends Resource
{
    protected static ?string $model = IdentityReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Corporate;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Review identitas';

    protected static ?string $modelLabel = 'Review identitas';

    protected static ?string $pluralModelLabel = 'Review identitas';

    public static function canViewAny(): bool
    {
        return EnterpriseFields::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = IdentityReview::query()->pending()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $input = fn (IdentityReview $record) => collect([$record->input['email'] ?? null, $record->input['phone'] ?? null, $record->input['employee_id'] ?? null, $record->input['member_number'] ?? null])->filter()->implode(' · ') ?: '-';

        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['subject', 'organization', 'schedule']))
            ->columns([
                TextColumn::make('created_at')->label('Masuk')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('input.name')->label('Peserta')->placeholder('(tanpa nama)')->description($input)->searchable(query: fn ($query, string $search) => $query->where('input', 'like', "%{$search}%")),
                TextColumn::make('reason')->label('Alasan')->badge()->color('warning')->formatStateUsing(fn ($state) => IdentityReview::REASONS[$state] ?? $state)->wrap(),
                TextColumn::make('candidates')->label('Kandidat')->placeholder('-')
                    ->state(fn (IdentityReview $record) => collect($record->candidates ?? [])->map(fn ($c) => "{$c['member_number']} {$c['name']} ({$c['matched_by']})")->implode("\n") ?: null)->wrap(),
                TextColumn::make('schedule.code')->label('Kelas')->placeholder('-')->description(fn (IdentityReview $record) => $record->organization?->legal_name),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => IdentityReview::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'pending' ? 'warning' : 'gray')->description(fn (IdentityReview $record) => $record->note),
            ])
            ->filters([
                SelectFilter::make('status')->options(IdentityReview::STATUSES)->default('pending'),
                SelectFilter::make('reason')->label('Alasan')->options(IdentityReview::REASONS),
            ])
            ->recordActions([
                Action::make('link')->label('Tautkan')->icon('heroicon-o-link')->color('primary')
                    ->visible(fn (IdentityReview $record) => $record->status === 'pending')
                    ->fillForm(fn (IdentityReview $record) => ['member_id' => $record->candidates[0]['member_id'] ?? null])
                    ->schema([
                        EnterpriseFields::member()->helperText('Kandidat pertama dipilih otomatis. Pastikan nama & kontak memang orang yang sama.'),
                        Textarea::make('note')->label('Catatan')->rows(2),
                    ])
                    ->action(fn (array $data, IdentityReview $record, Action $action) => EnterpriseFields::attempt($action,
                        fn () => app(IdentityMatchingService::class)->linkReview($record, Member::query()->findOrFail($data['member_id']), auth()->user(), $data['note'] ?? null),
                        'Peserta ditautkan ke member')),
                Action::make('create')->label('Buat member baru')->icon('heroicon-o-user-plus')->color('gray')
                    ->visible(fn (IdentityReview $record) => $record->status === 'pending' && ! empty($record->input['email']))
                    ->requiresConfirmation()->modalDescription('Akun baru dibuat dengan email peserta (tanpa kata sandi yang diketahui — peserta memakai "Lupa kata sandi" untuk masuk). Pastikan orang ini belum punya akun.')
                    ->action(fn (IdentityReview $record, Action $action) => EnterpriseFields::attempt($action,
                        fn () => app(IdentityMatchingService::class)->createMemberForReview($record, auth()->user()), 'Akun & member baru dibuat')),
                Action::make('rematch')->label('Cocokkan ulang')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (IdentityReview $record) => $record->status === 'pending' && $record->subject instanceof Enrollment)
                    ->action(function (IdentityReview $record): void {
                        $res = app(IdentityMatchingService::class)->resolveEnrollment($record->subject, auth()->user());
                        $res->outcome === IdentityMatch::USE_EXISTING
                            ? Notification::make()->title('Ditautkan otomatis ke member terverifikasi')->success()->send()
                            : Notification::make()->title('Masih perlu keputusan admin')->body(IdentityReview::REASONS[$record->fresh()->reason] ?? null)->warning()->send();
                    }),
                Action::make('dismiss')->label('Abaikan')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (IdentityReview $record) => $record->status === 'pending')
                    ->schema([Textarea::make('note')->label('Alasan')->required()->rows(2)])
                    ->action(fn (array $data, IdentityReview $record) => app(IdentityMatchingService::class)->dismissReview($record, auth()->user(), $data['note'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageIdentityReviews::route('/')];
    }
}
