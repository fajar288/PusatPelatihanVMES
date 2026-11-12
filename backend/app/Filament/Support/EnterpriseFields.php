<?php

namespace App\Filament\Support;

use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;

/** Komponen form Roadmap 5 (korporat) yang dipakai berulang. */
final class EnterpriseFields
{
    /** Hak akses menu korporat: izin corporate.manage atau super admin. */
    public static function canManage(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('corporate.manage') || $u?->isSuperAdmin());
    }

    /** Cari member berdasarkan nama, email, atau nomor member. */
    public static function member(string $name = 'member_id'): Select
    {
        return Select::make($name)->label('Member')->searchable()->required()
            ->getSearchResultsUsing(fn (string $search) => Member::query()->with('user:id,name,email')
                ->where(fn ($q) => $q->where('member_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
                ->limit(20)->get()->mapWithKeys(fn (Member $m) => [$m->id => self::memberLabel($m)])->all())
            ->getOptionLabelUsing(fn ($value) => ($m = Member::query()->with('user:id,name,email')->find($value)) ? self::memberLabel($m) : null);
    }

    /** Cari akun pengguna (untuk pengguna perusahaan). */
    public static function user(string $name = 'user_id'): Select
    {
        return Select::make($name)->label('Akun pengguna')->searchable()->required()
            ->getSearchResultsUsing(fn (string $search) => User::query()->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                ->limit(20)->get()->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} — {$u->email}"])->all())
            ->getOptionLabelUsing(fn ($value) => ($u = User::query()->find($value)) ? "{$u->name} — {$u->email}" : null)
            ->helperText('Akun harus sudah terdaftar (login dengan akun yang sama seperti peserta).');
    }

    public static function organization(string $name = 'organization_id', bool $required = true): Select
    {
        return Select::make($name)->label('Organisasi')->searchable()->preload()->required($required)->live()
            ->options(fn () => Organization::query()->orderBy('legal_name')->get()->mapWithKeys(fn (Organization $o) => [$o->id => $o->code.' · '.$o->name()])->all());
    }

    /** Unit milik organisasi yang dipilih di field lain. */
    public static function unit(string $name = 'unit_id', string $orgField = 'organization_id'): Select
    {
        return Select::make($name)->label('Unit (departemen / cost center)')->searchable()->nullable()
            ->options(fn (Get $get) => OrganizationUnit::query()->where('organization_id', $get($orgField))->where('is_active', true)->orderBy('name')
                ->get()->mapWithKeys(fn (OrganizationUnit $u) => [$u->id => $u->label().' ('.(OrganizationUnit::TYPES[$u->type] ?? $u->type).')'])->all());
    }

    /**
     * Jalankan aksi service; penolakan aturan bisnis (ValidationException) ditampilkan sebagai notifikasi merah
     * dan aksi dihentikan (modal tetap terbuka) — galat dengan kunci non-field tidak akan terlihat di form.
     */
    public static function attempt(Action $action, callable $fn, ?string $success = null): mixed
    {
        try {
            $result = $fn();
        } catch (ValidationException $e) {
            Notification::make()->title('Tidak dapat diproses')->body(collect($e->errors())->flatten()->first())->danger()->send();
            $action->halt();

            return null;
        }
        if ($success) {
            Notification::make()->title($success)->success()->send();
        }

        return $result;
    }

    public static function memberLabel(Member $m): string
    {
        return $m->member_number.' — '.($m->user?->name ?? '?').' ('.($m->user?->email ?? '-').')';
    }
}
