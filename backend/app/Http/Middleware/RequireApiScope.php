<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\CorporateUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.scope:catalog.read` — klien API wajib memiliki scope tersebut.
 * Scope enterprise juga mensyaratkan peran pengguna perusahaan yang diwakili punya kemampuan yang sesuai
 * (ApiClient::SCOPE_CAPABILITIES) — scope tidak pernah memberi lebih dari peran perusahaan itu sendiri.
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $client = $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);
        if (! $client instanceof ApiClient) {
            return AuthenticateApiClient::error('unauthenticated', 'API key tidak ada atau tidak valid.', 401);
        }
        foreach ($scopes as $scope) {
            if (! $client->hasScope($scope)) {
                return AuthenticateApiClient::error('insufficient_scope', "Klien API tidak memiliki scope \"$scope\".", 403);
            }
            if (ApiClient::isEnterpriseScope($scope)) {
                $cu = $request->attributes->get(EnsureOrganizationClient::ATTRIBUTE);
                if (! $cu instanceof CorporateUser) {
                    return AuthenticateApiClient::error('organization_required', 'Endpoint ini hanya untuk klien API yang terikat organisasi.', 403);
                }
                $needed = ApiClient::SCOPE_CAPABILITIES[$scope] ?? [];
                if ($needed !== [] && collect($needed)->doesntContain(fn (string $cap) => $cu->allows($cap))) {
                    $role = CorporateUser::ROLES[$cu->role] ?? $cu->role;

                    return AuthenticateApiClient::error('insufficient_role', "Peran pengguna perusahaan yang diwakili ($role) tidak mengizinkan \"$scope\".", 403);
                }
            }
        }

        return $next($request);
    }
}
