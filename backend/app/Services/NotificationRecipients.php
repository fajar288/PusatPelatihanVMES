<?php

namespace App\Services;

use App\Models\CorporateUser;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Support\Notify\Recipient;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Penerima per scope untuk Notification Engine (PRD Roadmap 5 §6.1 "Recipient Scope").
 * Audiens perusahaan HANYA untuk enrollment yang disponsori organisasinya, dalam batas unit & kebijakan exit
 * (CorporateAccess — sama dengan Corporate Portal), sehingga self-funded/sponsor lain tidak pernah bocor lewat notifikasi.
 */
class NotificationRecipients
{
    public function __construct(private readonly CorporateAccess $access) {}

    /** @param iterable<Enrollment> $enrollments @return Collection<int, Recipient> */
    public function participants(iterable $enrollments, array $vars = []): Collection
    {
        return NotificationCenter::usersForEnrollments($enrollments)->map(fn (User $u) => Recipient::user($u, Recipient::PARTICIPANT, $vars));
    }

    /**
     * Pengguna perusahaan sponsor: satu penerima per (pengguna × organisasi) dengan variabel ringkasan peserta yang boleh ia lihat.
     *
     * @param  iterable<Enrollment>  $enrollments
     * @param  bool  $progressDetail  isi pesan memuat detail progres → ikuti CorporateAccess::canViewProgress (exit policy)
     * @param  callable(Organization, Collection<int, Enrollment>): ?string|null  $url
     * @return Collection<int, Recipient>
     */
    public function corporate(iterable $enrollments, string $capability = 'view_progress', bool $progressDetail = false, ?callable $url = null): Collection
    {
        $out = collect();
        $groups = collect($enrollments)->filter(fn (Enrollment $e) => $e->isCorporate() && $e->sponsor_organization_id)->groupBy('sponsor_organization_id');
        foreach ($groups as $orgId => $group) {
            $org = Organization::query()->find($orgId);
            if (! $org?->isActive()) {
                continue;
            }
            foreach ($this->corporateUsers($org, $capability) as $cu) {
                $visible = $group->filter(fn (Enrollment $e) => $progressDetail ? $this->access->canViewProgress($cu, $e) : $this->access->canView($cu, $e))->values();
                if ($visible->isEmpty()) {
                    continue;
                }
                $out->push(new Recipient(Recipient::CORPORATE, $cu->user, $cu->user->name, $cu->user->email, null, $org->id, [
                    'organization' => $org->name(),
                    'count' => (string) $visible->count(),
                    'participants' => self::nameList($visible->pluck('participant_name')),
                    'participant' => (string) $visible->first()->participant_name,
                ], $url ? $url($org, $visible) : '/corporate/'.$org->code.'/peserta'));
            }
        }

        return $out;
    }

    /** Pengguna perusahaan untuk urusan tingkat organisasi (mis. invoice → kemampuan view_finance). @return Collection<int, Recipient> */
    public function corporateForOrganization(?Organization $org, string $capability, array $vars = [], ?string $url = null): Collection
    {
        if (! $org?->isActive()) {
            return collect();
        }

        return $this->corporateUsers($org, $capability)->map(fn (CorporateUser $cu) => new Recipient(
            Recipient::CORPORATE, $cu->user, $cu->user->name, $cu->user->email, null, $org->id, ['organization' => $org->name()] + $vars, $url,
        ))->values();
    }

    /** Trainer yang ditugaskan di kelas (akun bila ada; tanpa akun → email/telepon data trainer). @return Collection<int, Recipient> */
    public function trainers(TrainingSchedule $s, array $vars = []): Collection
    {
        return $s->trainers()->where('is_active', true)->get()->map(function (Trainer $t) use ($vars) {
            $user = $t->user_id ? User::query()->find($t->user_id) : null;

            return new Recipient(Recipient::TRAINER, $user, $t->name, $t->email ?: $user?->email, $t->phone, null, $vars, '/trainer');
        })->filter(fn (Recipient $r) => $r->user || $r->email || $r->phone)->values();
    }

    /** Staf VMES yang memiliki izin tertentu (notifikasi "Dashboard" = lonceng panel admin). @return Collection<int, Recipient> */
    public function admins(string $permission, ?User $except = null, array $vars = [], ?string $url = null): Collection
    {
        $roles = Role::query()->whereHas('permissions', fn ($q) => $q->where('slug', $permission))->pluck('slug')->push(User::ROLE_SUPER_ADMIN)->unique()->all();

        return User::query()->where(fn ($q) => $q->whereIn('role', $roles)->orWhereHas('roles', fn ($r) => $r->whereIn('slug', $roles)))
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))->orderBy('id')->get()
            ->map(fn (User $u) => Recipient::user($u, Recipient::ADMIN, $vars, null, $url))->values();
    }

    /** URL panel admin; aman dipanggil di luar request panel. */
    public static function adminUrl(callable $resolver, string $fallback = '/admin'): string
    {
        try {
            $url = (string) $resolver();

            return parse_url($url, PHP_URL_PATH) ?: $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** "Rina, Tono dan 3 lainnya" @param Collection<int, ?string> $names */
    public static function nameList(Collection $names, int $max = 10): string
    {
        $names = $names->filter()->values();
        if ($names->count() <= $max) {
            return $names->count() > 1 ? $names->slice(0, -1)->implode(', ').' dan '.$names->last() : (string) $names->first();
        }

        return $names->take($max)->implode(', ').' dan '.($names->count() - $max).' lainnya';
    }

    /** @return Collection<int, CorporateUser> */
    private function corporateUsers(Organization $org, string $capability): Collection
    {
        return CorporateUser::query()->active()->where('organization_id', $org->id)->with(['user', 'organization', 'unit'])->orderBy('id')->get()
            ->filter(fn (CorporateUser $cu) => $cu->user && $cu->allows($capability))->values();
    }
}
