<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** PDF invoice dari panel admin (sesi web Filament) — izin payments.verify. */
class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, InvoiceService $invoices): Response
    {
        $u = $request->user();
        abort_unless($u && ($u->hasPermission('payments.verify') || $u->isSuperAdmin()), 403);

        return response($invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"',
        ]);
    }
}
