<?php

namespace App\Http\Controllers\PublicApi\V1\Enterprise;

use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Services\CorporateReporting;
use App\Support\Enterprise\EnterpriseApiPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API enterprise — status pembayaran (invoice + transaksi manual, scope payments.read, peran view_finance) dan status
 * pengiriman notifikasi ke pengguna perusahaan (scope notifications.read). Pembayaran tetap manual: status invoice
 * hanya berubah lewat verifikasi finance VMES, API ini hanya membaca.
 */
class AccountController extends EnterpriseController
{
    public function __construct(private CorporateReporting $reporting) {}

    /** GET /payments?status=&updated_since= */
    public function payments(Request $request): JsonResponse
    {
        $cu = $this->cu($request);
        $p = $this->listParams($request, ['status' => ['nullable', Rule::in(array_keys(Invoice::STATUSES))]]);
        $q = $this->reporting->invoicesQuery($cu)->with(['registration:id,registration_number', 'trainingRequest:id,code'])
            ->when($p['status'] ?? null, fn (Builder $w, $s) => $w->where('status', $s))->latest('id');

        return $this->paginate($request, $q, fn (Invoice $i) => EnterpriseApiPresenter::invoice($i));
    }

    /** GET /payments/{number} — invoice + item + transaksi (tanpa berkas bukti transfer). */
    public function payment(Request $request, string $number): JsonResponse
    {
        $i = $this->reporting->invoicesQuery($this->cu($request))->where('invoice_number', $number)
            ->with(['registration:id,registration_number', 'trainingRequest:id,code', 'items', 'transactions'])->first();

        return $i ? response()->json(['data' => EnterpriseApiPresenter::invoice($i, true)]) : $this->notFound('Invoice tidak ditemukan.');
    }

    /** GET /notifications?status=&channel=&event=&updated_since= — pengiriman ke pengguna perusahaan organisasi ini. */
    public function notifications(Request $request): JsonResponse
    {
        $cu = $this->cu($request);
        $p = $this->listParams($request, [
            'status' => ['nullable', Rule::in(array_keys(NotificationDelivery::STATUSES))],
            'channel' => ['nullable', Rule::in(['database', 'mail', 'webpush', 'whatsapp'])],
            'event' => ['nullable', 'string', 'max:60'],
        ]);
        $q = NotificationDelivery::query()->where('audience', 'corporate')->where('organization_id', $cu->organization_id)->with('event:id,key')
            ->when($p['status'] ?? null, fn (Builder $w, $s) => $w->where('status', $s))
            ->when($p['channel'] ?? null, fn (Builder $w, $c) => $w->where('channel', $c))
            ->when($p['event'] ?? null, fn (Builder $w, $e) => $w->whereHas('event', fn (Builder $x) => $x->where('key', $e)))
            ->latest('id');

        return $this->paginate($request, $q, fn (NotificationDelivery $d) => EnterpriseApiPresenter::delivery($d));
    }
}
