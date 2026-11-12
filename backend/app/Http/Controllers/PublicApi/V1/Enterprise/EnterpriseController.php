<?php

namespace App\Http\Controllers\PublicApi\V1\Enterprise;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Middleware\EnsureOrganizationClient;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\CorporateUser;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Dasar endpoint API enterprise (Roadmap 5E, PRD Roadmap 5 §7). Organisasi & pengguna perusahaan yang diwakili selalu
 * berasal dari klien API (middleware `api.organization`), bukan dari URL/isi permintaan (§20.21).
 */
abstract class EnterpriseController extends Controller
{
    protected function cu(Request $request): CorporateUser
    {
        return $request->attributes->get(EnsureOrganizationClient::ATTRIBUTE);
    }

    protected function client(Request $request): ApiClient
    {
        return $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);
    }

    /** Parameter umum daftar: `per_page` (maks. $max) & `updated_since`. */
    protected function listParams(Request $request, array $rules = [], int $max = 100): array
    {
        return $request->validate($rules + [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$max],
            'page' => ['nullable', 'integer', 'min:1'],
            'updated_since' => ['nullable', 'date'],
        ]);
    }

    /** Daftar berhalaman berbentuk `data` + `links` + `meta` (sama dengan endpoint API Publik lain). */
    protected function paginate(Request $request, Builder $query, Closure $map, int $default = 25, bool $applySince = true): JsonResponse
    {
        if ($applySince && ($since = $request->query('updated_since'))) {
            $query->where($query->getModel()->qualifyColumn('updated_at'), '>=', Carbon::parse($since));
        }
        $page = $query->paginate(max(1, (int) $request->integer('per_page', $default)))->withQueryString();

        return JsonResource::collection($page->through($map))->response();
    }

    protected function notFound(string $message): JsonResponse
    {
        return AuthenticateApiClient::error('not_found', $message, 404);
    }

    /** Akses data peserta lewat API diaudit (§20.21 "participant access"). */
    protected function auditAccess(Request $request, string $action, ?Model $subject, array $meta = []): void
    {
        $cu = $this->cu($request);
        AuditLog::record($action, $subject ?? $cu->organization, null, $meta + [
            'organization_id' => $cu->organization_id, 'corporate_user_id' => $cu->id, 'endpoint' => $request->route()?->getName(),
        ], $cu->user);
    }
}
