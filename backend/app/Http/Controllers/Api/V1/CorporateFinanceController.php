<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\AuditLog;
use App\Models\CorporateUser;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\CorporateReporting;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Keuangan perusahaan (Roadmap 5C) di /corporate/organizations/{org}/invoices: finance & owner perusahaan melihat invoice
 * organisasinya, mengunduh PDF, dan mengunggah bukti transfer. Invoice organisasi lain → 404 (§20.21).
 */
class CorporateFinanceController extends Controller
{
    public function __construct(private CorporateController $corporate, private CorporateReporting $reporting) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->finance($request, $organization);
        $rows = $this->query($cu)->with('registration', 'trainingRequest', 'transactions')->latest('id')->get();
        $open = $rows->whereIn('status', ['pending', 'failed', 'expired']);

        return response()->json([
            'data' => InvoiceResource::collection($rows)->resolve($request),
            'summary' => [
                'outstanding' => (float) $open->sum(fn (Invoice $i) => $i->outstanding()),
                'openCount' => $open->count(),
                'paid' => (float) $rows->sum(fn (Invoice $i) => (float) $i->amount_paid - (float) $i->amount_refunded),
                'awaiting' => $rows->filter(fn (Invoice $i) => $i->transactions->contains('status', 'pending'))->count(),
            ],
            'canUpload' => true,
        ]);
    }

    public function show(Request $request, Organization $organization, Invoice $invoice): InvoiceResource
    {
        $cu = $this->finance($request, $organization);

        return new InvoiceResource($this->scoped($cu, $invoice)->load('registration', 'trainingRequest', 'items', 'transactions', 'refunds'));
    }

    public function pdf(Request $request, Organization $organization, Invoice $invoice, InvoiceService $invoices): Response
    {
        $cu = $this->finance($request, $organization);
        $invoice = $this->scoped($cu, $invoice);
        AuditLog::record('corporate.invoice_downloaded', $invoice, null, ['organization' => $organization->code], $request->user());

        return response($invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** POST .../invoices/{number}/proof — finance perusahaan mengunggah bukti transfer; status berubah hanya lewat verifikasi VMES. */
    public function proof(Request $request, Organization $organization, Invoice $invoice, PaymentService $payments): InvoiceResource
    {
        $cu = $this->finance($request, $organization);
        $invoice = $this->scoped($cu, $invoice);
        $data = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:9999999999'],
            'transfer_date' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_account_id' => ['nullable', 'integer', Rule::exists('payment_accounts', 'id')->where('is_active', true)],
            'payer_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $payments->submitProof($invoice, $request->user(), $request->file('proof'), $data, 'corporate');

        return new InvoiceResource($invoice->refresh()->load('registration', 'trainingRequest', 'items', 'transactions', 'refunds'));
    }

    private function finance(Request $request, Organization $organization): CorporateUser
    {
        $cu = $this->corporate->member($request, $organization);
        abort_unless($cu->allows('view_finance'), 403, 'Peran Anda tidak dapat melihat keuangan perusahaan.');

        return $cu;
    }

    /**
     * Invoice organisasi: training request (mengikuti batas unit pengguna) + pendaftaran kolektif yang ditautkan ke
     * organisasi (hanya untuk pengguna tanpa batas unit).
     */
    private function query(CorporateUser $cu): Builder
    {
        return $this->reporting->invoicesQuery($cu);   // sama dengan API enterprise GET /payments
    }

    private function scoped(CorporateUser $cu, Invoice $invoice): Invoice
    {
        abort_unless($this->query($cu)->whereKey($invoice->id)->exists(), 404);

        return $invoice;
    }
}
