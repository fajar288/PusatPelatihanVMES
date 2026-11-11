<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Pratinjau/unduh PDF sertifikat & contoh template dari panel admin (sesi web Filament). */
class CertificatePdfController extends Controller
{
    public function __construct(private readonly CertificateService $certificates) {}

    /** GET /admin/certificates/{certificate:id}/pdf?download=1 */
    public function certificate(Request $request, int $certificate): Response
    {
        $this->authorizeAdmin($request);
        $c = Certificate::query()->findOrFail($certificate);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($this->certificates->pdfContents($c), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->certificates->fileName($c).'"',
        ]);
    }

    /** GET /admin/certificate-templates/{template}/preview */
    public function template(Request $request, int $template): Response
    {
        $this->authorizeAdmin($request);
        $t = CertificateTemplate::query()->findOrFail($template);

        return response($this->certificates->renderPdf($this->certificates->sample($t)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="contoh-template-'.$t->id.'.pdf"',
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ($u->hasPermission('certificates.manage') || $u->isAdmin()), 403);
    }
}
