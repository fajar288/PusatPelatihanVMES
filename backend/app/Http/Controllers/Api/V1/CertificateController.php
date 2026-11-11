<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Sertifikat (PRD §5.10): verifikasi publik, daftar & unduh sertifikat member (dashboard alumni §5.6). */
class CertificateController extends Controller
{
    public function __construct(private readonly CertificateService $certificates) {}

    /** GET /verify/{number}?k=kode — publik, AC-10. */
    public function verify(Request $request, string $number): JsonResponse
    {
        $c = Certificate::query()->where('number', $number)->orWhere('number', str_replace('/', '-', $number))->first();
        if (! $c) {
            return response()->json(['data' => null, 'message' => 'Nomor sertifikat tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $this->certificates->verify($c, $request->query('k'))]);
    }

    /** GET /me/certificates — semua sertifikat milik akun (terbit, dicabut, diganti; draf tidak ditampilkan). */
    public function index(Request $request): JsonResponse
    {
        $items = Certificate::query()->ownedBy($request->user())->whereIn('status', ['issued', 'revoked', 'replaced'])
            ->with('schedule', 'replacedBy', 'replaces')->orderByDesc('issued_at')->orderByDesc('id')->get();

        return response()->json(['data' => $items->map(fn (Certificate $c) => CertificateService::toArray($c))->values()]);
    }

    /** GET /me/certificates/{certificate} */
    public function show(Request $request, Certificate $certificate): JsonResponse
    {
        abort_unless(Certificate::query()->ownedBy($request->user())->whereKey($certificate->id)->exists(), 404);
        abort_if(in_array($certificate->status, ['draft', 'pending_validation'], true), 404);

        return response()->json(['data' => CertificateService::toArray($certificate) + ['history' => CertificateService::history($certificate)->filter(fn ($h) => in_array($h['action'], ['certificate.issued', 'certificate.revoked', 'certificate.replaced'], true))->values()]]);
    }

    /** GET /me/certificates/{certificate}/pdf — unduh PDF (hanya yang terbit). */
    public function pdf(Request $request, Certificate $certificate): Response
    {
        abort_unless(Certificate::query()->ownedBy($request->user())->whereKey($certificate->id)->exists(), 404);
        abort_unless($certificate->isIssued(), 410, 'Sertifikat ini '.strtolower($certificate->statusLabel()).'.'.($certificate->replacedBy ? ' Gunakan sertifikat pengganti '.$certificate->replacedBy->number.'.' : ''));
        AuditLog::record('certificate.downloaded', $certificate, null, ['by' => 'member'], $request->user());

        return response($this->certificates->pdfContents($certificate), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->certificates->fileName($certificate).'"',
        ]);
    }
}
