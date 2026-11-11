<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `api.scope:catalog.read` — klien API wajib memiliki scope tersebut. */
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
        }

        return $next($request);
    }
}
