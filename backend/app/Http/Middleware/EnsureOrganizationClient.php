<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\CorporateUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.organization` — endpoint enterprise (Roadmap 5E): klien harus terikat organisasi AKTIF dan pengguna perusahaan
 * yang diwakilinya masih AKTIF di organisasi itu. Organisasi tidak pernah diambil dari URL/isi permintaan (§20.21).
 * Pengguna perusahaan (peran + batas unit) disimpan di atribut request `corporate_user` untuk dipakai service yang sama
 * dengan Corporate Portal.
 */
class EnsureOrganizationClient
{
    public const ATTRIBUTE = 'corporate_user';

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);
        if (! $client instanceof ApiClient) {
            return AuthenticateApiClient::error('unauthenticated', 'API key tidak ada atau tidak valid.', 401);
        }
        if (! $client->organization_id || ! $client->corporate_user_id) {
            return AuthenticateApiClient::error('organization_required', 'Endpoint ini hanya untuk klien API yang terikat organisasi. Hubungi PPTI.', 403);
        }

        $cu = CorporateUser::query()->with(['organization', 'unit', 'user'])->find($client->corporate_user_id);
        if (! $cu || $cu->organization_id !== $client->organization_id || ! $cu->organization?->isActive()) {
            return AuthenticateApiClient::error('organization_inactive', 'Organisasi klien ini tidak aktif.', 403);
        }
        if (! $cu->isActive() || ! $cu->user) {
            return AuthenticateApiClient::error('acting_user_inactive', 'Akses pengguna perusahaan yang diwakili klien ini sudah dicabut. Hubungi PPTI untuk menetapkan pengguna lain.', 403);
        }
        $request->attributes->set(self::ATTRIBUTE, $cu);

        return $next($request);
    }
}
