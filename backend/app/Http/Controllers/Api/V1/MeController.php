<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\RegistrationResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Registration;
use App\Services\InvoiceService;
use App\Services\MemberService;
use App\Services\MonitoringService;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Endpoint portal member (/portal): profil, pendaftaran, pembayaran, notifikasi. */
class MeController extends Controller
{
    /** GET /me */
    public function show(Request $request, MemberService $members): UserResource
    {
        $user = $request->user();
        if (! $user->member) {
            $members->createForUser($user);
        }

        return new UserResource($user->load('member'));
    }

    /** PATCH /me — profil member */
    public function update(Request $request, MemberService $members): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'company' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'education' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:80'],
            'communication_pref' => ['nullable', 'array'],
            'communication_pref.email' => ['boolean'],
            'communication_pref.whatsapp' => ['boolean'],
        ]);
        $member = $user->member ?? $members->createForUser($user);
        $before = $member->only(array_keys($data));
        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
            unset($data['name']);
        }
        $member->update($data);
        AuditLog::record('member.profile_updated', $member, $before, $data, $user);

        return new UserResource($user->refresh()->load('member'));
    }

    /** GET /me/registrations */
    public function registrations(Request $request): AnonymousResourceCollection
    {
        return RegistrationResource::collection(Registration::query()->ownedBy($request->user())->with('schedule.package', 'invoices')->latest()->get());
    }

    /** GET /me/registrations/{registration} */
    public function registration(Request $request, Registration $registration): RegistrationResource
    {
        Gate::authorize('view', $registration);

        return new RegistrationResource($registration->load('schedule.package', 'invoices.items', 'invoices.transactions', 'participants'));
    }

    /** POST /me/registrations/{registration}/cancel */
    public function cancel(Request $request, Registration $registration, RegistrationService $service): RegistrationResource
    {
        Gate::authorize('cancel', $registration);
        $service->transition($registration, RegistrationStatus::Cancelled, $request->user(), $request->string('reason')->toString() ?: 'Dibatalkan oleh pendaftar');

        return new RegistrationResource($registration->load('schedule.package', 'invoices'));
    }

    /**
     * POST /me/registrations/{registration}/payment-proof (multipart: proof, amount?, transfer_date?, payment_account_id?, note?)
     * Bukti untuk invoice terbuka pertama pendaftaran ini (kompatibel dengan alur lama).
     */
    public function paymentProof(Request $request, Registration $registration, PaymentService $payments): InvoiceResource
    {
        Gate::authorize('view', $registration);
        $data = $this->validateProof($request);
        $invoice = $registration->invoices()->whereIn('status', [...Invoice::PAYABLE, 'expired'])->orderBy('installment_no')->first()
            ?? $registration->invoices()->where('status', 'paid')->first();
        abort_unless($invoice, 422, 'Pendaftaran belum pada tahap pembayaran.');
        $payments->submitProof($invoice, $request->user(), $request->file('proof'), $data, 'member');

        return new InvoiceResource($invoice->refresh()->load('items', 'transactions', 'refunds', 'registration'));
    }

    /** GET /me/payments — semua invoice pendaftaran milik pengguna (nama lama dipertahankan). */
    public function payments(Request $request): AnonymousResourceCollection
    {
        $ids = Registration::query()->ownedBy($request->user())->pluck('id');

        return InvoiceResource::collection(Invoice::query()->whereIn('registration_id', $ids)->where('status', '!=', 'cancelled')
            ->with('registration', 'items', 'transactions', 'refunds')->latest('id')->get());
    }

    /** GET /me/invoices/{invoice} */
    public function invoice(Request $request, Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($this->ownedInvoice($request, $invoice)->load('registration', 'items', 'transactions', 'refunds'));
    }

    /** POST /me/invoices/{invoice}/proof — unggah bukti transfer untuk invoice tertentu (termin). */
    public function invoiceProof(Request $request, Invoice $invoice, PaymentService $payments): InvoiceResource
    {
        $invoice = $this->ownedInvoice($request, $invoice);
        $payments->submitProof($invoice, $request->user(), $request->file('proof') ?? abort(422), $this->validateProof($request), 'member');

        return new InvoiceResource($invoice->refresh()->load('registration', 'items', 'transactions', 'refunds'));
    }

    /** GET /me/invoices/{invoice}/pdf */
    public function invoicePdf(Request $request, Invoice $invoice, InvoiceService $invoices): Response
    {
        $invoice = $this->ownedInvoice($request, $invoice);

        return response($invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Invoice pendaftaran milik pengguna; invoice lain (termasuk milik perusahaan) → 404, bukan 403. */
    private function ownedInvoice(Request $request, Invoice $invoice): Invoice
    {
        $owned = $invoice->registration_id && Registration::query()->ownedBy($request->user())->whereKey($invoice->registration_id)->exists();
        abort_unless($owned && $invoice->status !== 'cancelled', 404);

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function validateProof(Request $request): array
    {
        return $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:9999999999'],
            'transfer_date' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_account_id' => ['nullable', 'integer', Rule::exists('payment_accounts', 'id')->where('is_active', true)],
            'payer_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** GET /me/payment-accounts — rekening/VA tujuan pembayaran manual yang aktif. */
    public function paymentAccounts(): JsonResponse
    {
        return response()->json(['data' => PaymentAccount::query()->shown()->get()->map->toApi()->values()]);
    }

    /** GET /me/enrollments — "Pelatihan Saya": kelas, trainer, sesi, kehadiran */
    public function enrollments(Request $request): AnonymousResourceCollection
    {
        $q = Enrollment::query()->ownedBy($request->user())
            ->with(['schedule.package', 'schedule.trainers', 'schedule.sessions', 'attendances', 'registration', 'certificate'])
            ->latest('enrolled_at');

        return EnrollmentResource::collection($q->get());
    }

    /** GET /me/progress — progress overview per enrollment (PRD §5.9): kehadiran, materi, nilai, praktik, kompetensi & catatan trainer yang sudah divalidasi. */
    public function progress(Request $request, MonitoringService $monitoring): JsonResponse
    {
        $items = Enrollment::query()->ownedBy($request->user())->with('schedule.package')->latest('enrolled_at')->get()
            ->map(fn (Enrollment $e) => $monitoring->overview($e, true))->values();

        return response()->json(['data' => $items]);
    }
}
