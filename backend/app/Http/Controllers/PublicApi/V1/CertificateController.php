<?php

namespace App\Http\Controllers\PublicApi\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiClient;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** API Publik v1 — verifikasi sertifikat (scope certificates.verify), mis. untuk HR/rekruter atau principal vendor. */
class CertificateController extends Controller
{
    /** GET /certificates/{number}?code= — `code` = kode verifikasi pada sertifikat (opsional, dicocokkan bila dikirim). */
    public function verify(Request $request, string $number, CertificateService $certificates): JsonResponse
    {
        $request->validate(['code' => ['nullable', 'string', 'max:40']]);
        $c = Certificate::query()->where('number', $number)->first();
        if (! $c) {
            return AuthenticateApiClient::error('not_found', 'Nomor sertifikat tidak ditemukan.', 404);
        }
        $data = collect($certificates->verify($c, $request->query('code')))
            ->reject(fn ($v, string $k) => str_ends_with($k, 'Label') && $k !== 'statusLabel' && $k !== 'typeLabel')
            ->mapWithKeys(fn ($v, string $k) => [Str::snake($k) => $v]);

        return response()->json(['data' => $data->all()]);
    }
}
