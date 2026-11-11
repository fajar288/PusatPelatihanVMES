<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RegistrationResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Registration;
use App\Services\MemberService;
use App\Services\MonitoringService;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        return RegistrationResource::collection(Registration::query()->ownedBy($request->user())->with('schedule.package', 'payments')->latest()->get());
    }

    /** GET /me/registrations/{registration} */
    public function registration(Request $request, Registration $registration): RegistrationResource
    {
        Gate::authorize('view', $registration);

        return new RegistrationResource($registration->load('schedule.package', 'payments', 'participants'));
    }

    /** POST /me/registrations/{registration}/cancel */
    public function cancel(Request $request, Registration $registration, RegistrationService $service): RegistrationResource
    {
        Gate::authorize('cancel', $registration);
        $service->transition($registration, RegistrationStatus::Cancelled, $request->user(), $request->string('reason')->toString() ?: 'Dibatalkan oleh pendaftar');

        return new RegistrationResource($registration->load('schedule.package', 'payments'));
    }

    /** POST /me/registrations/{registration}/payment-proof (multipart: proof) */
    public function paymentProof(Request $request, Registration $registration, RegistrationService $service): PaymentResource
    {
        Gate::authorize('view', $registration);
        $request->validate(['proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'], 'note' => ['nullable', 'string', 'max:500']]);
        abort_unless(in_array($registration->status->value, ['waiting_payment', 'payment_verification'], true), 422, 'Pendaftaran belum pada tahap pembayaran.');

        $payment = $registration->payments()->whereIn('status', ['unpaid', 'submitted', 'rejected'])->first()
            ?? Payment::query()->create(['registration_id' => $registration->id, 'invoice_number' => Payment::nextNumber(), 'amount' => $registration->total ?? 0, 'status' => 'unpaid']);
        $path = $request->file('proof')->store('payments/'.$registration->registration_number, config('filesystems.default'));
        $payment->update(['proof_path' => $path, 'proof_uploaded_at' => now(), 'status' => 'submitted', 'note' => $request->string('note')->toString() ?: $payment->note]);
        AuditLog::record('payment.proof_uploaded', $payment, null, ['path' => $path], $request->user());

        if ($registration->status === RegistrationStatus::WaitingPayment) {
            $service->transition($registration, RegistrationStatus::PaymentVerification, $request->user(), 'Bukti pembayaran diunggah');
        }

        return new PaymentResource($payment->refresh());
    }

    /** GET /me/payments */
    public function payments(Request $request): AnonymousResourceCollection
    {
        $ids = Registration::query()->ownedBy($request->user())->pluck('id');

        return PaymentResource::collection(Payment::query()->whereIn('registration_id', $ids)->with('registration')->latest()->get());
    }

    /** GET /me/enrollments — "Pelatihan Saya": kelas, trainer, sesi, kehadiran */
    public function enrollments(Request $request): AnonymousResourceCollection
    {
        $q = Enrollment::query()->ownedBy($request->user())
            ->with(['schedule.package', 'schedule.trainers', 'schedule.sessions', 'attendances', 'registration'])
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

    /** GET /me/notifications — riwayat peristiwa akun (audit) sebagai notifikasi aplikasi sederhana. */
    public function notifications(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $regIds = Registration::query()->ownedBy($user)->pluck('id');
        $logs = AuditLog::query()
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('auditable_type', Registration::class)->whereIn('auditable_id', $regIds))
                ->orWhere(fn ($w) => $w->where('auditable_type', Member::class)->where('auditable_id', $user->member?->id ?? 0)))
            ->latest('created_at')->limit(50)->get();

        return AuditLogResource::collection($logs);
    }
}
