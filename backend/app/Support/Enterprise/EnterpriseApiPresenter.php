<?php

namespace App\Support\Enterprise;

use App\Models\Certificate;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PaymentTransaction;
use App\Models\RequestSeat;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;

/**
 * Bentuk JSON (snake_case) API enterprise & payload webhook enterprise (Roadmap 5E). Satu sumber agar respons API dan
 * webhook selalu sama. Data minimal sesuai §20.11: tanpa email/HP peserta, catatan privat trainer, catatan internal VMES,
 * maupun berkas bukti transfer.
 */
final class EnterpriseApiPresenter
{
    public static function organization(Organization $o, CorporateUser $cu): array
    {
        $scope = $cu->unitScope();

        return [
            'code' => $o->code,
            'name' => $o->name(),
            'legal_name' => $o->legal_name,
            'industry' => $o->industry,
            'city' => $o->city,
            'status' => $o->status,
            'requires_approval' => $o->requiresApproval(),
            'exit_access' => $o->exitAccess(),
            'acting_as' => [
                'role' => $cu->role,
                'role_label' => CorporateUser::ROLES[$cu->role] ?? $cu->role,
                'unit' => self::unit($cu->unit),
                'capabilities' => CorporateUser::CAPABILITIES[$cu->role] ?? [],
            ],
            'units' => OrganizationUnit::query()->where('organization_id', $o->id)->where('is_active', true)
                ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))->orderBy('name')->get()
                ->map(fn (OrganizationUnit $u) => self::unit($u) + ['type' => $u->type])->values()->all(),
        ];
    }

    public static function unit(?OrganizationUnit $u): ?array
    {
        return $u ? ['code' => $u->code, 'name' => $u->name] : null;
    }

    public static function schedule(?TrainingSchedule $s): ?array
    {
        return $s ? ['code' => $s->code, 'title' => $s->title ?? $s->package?->title, 'start_date' => $s->start_date?->toDateString(), 'end_date' => $s->end_date?->toDateString()] : null;
    }

    /** Corporate Training Request. Penawaran & invoice hanya untuk peran yang mengurus request/keuangan (sama dengan portal). */
    public static function trainingRequest(TrainingRequest $r, ?CorporateUser $cu, bool $detail = false): array
    {
        $finance = $cu === null || $cu->allows('view_finance') || $cu->allows('manage_requests') || $cu->allows('approve_requests');
        $base = [
            'code' => $r->code,
            'status' => $r->status,
            'status_label' => $r->label(),
            'program' => $r->package ? ['slug' => $r->package->slug, 'title' => $r->package->title] : null,
            'delivery' => $r->delivery,
            'target_date' => $r->target_date?->toDateString(),
            'location' => $r->location,
            'unit' => self::unit($r->unit),
            'seats' => (int) $r->seats,
            'seats_filled' => (int) ($r->filled_count ?? $r->requestSeats()->whereIn('status', ['assigned', 'enrolled'])->count()),
            'seats_invited' => (int) ($r->invited_count ?? $r->requestSeats()->where('status', 'invited')->count()),
            'schedule' => self::schedule($r->schedule),
            'quote' => $finance && $r->quote_total !== null ? [
                'price_per_seat' => (float) $r->price_per_seat, 'discount' => (float) $r->quote_discount, 'total' => (float) $r->quote_total,
                'valid_until' => $r->quote_valid_until?->toDateString(), 'notes' => $r->quote_notes, 'currency' => 'IDR',
            ] : null,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'quoted_at' => $r->quoted_at?->toIso8601String(),
            'approved_at' => $r->approved_at?->toIso8601String(),
            'paid_at' => $r->paid_at?->toIso8601String(),
            'scheduled_at' => $r->scheduled_at?->toIso8601String(),
            'cancelled_at' => $r->cancelled_at?->toIso8601String(),
            'cancel_reason' => $r->cancel_reason,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
        if (! $detail) {
            return $base;
        }
        $invoice = $finance ? $r->invoices()->where('status', '!=', 'cancelled')->first() : null;

        return $base + [
            'trainer_requirements' => $r->trainer_requirements,
            'notes' => $r->notes,
            'invoice' => $invoice ? ['number' => $invoice->invoice_number, 'status' => $invoice->status, 'total' => (float) $invoice->total, 'outstanding' => $invoice->outstanding(), 'due_date' => $invoice->due_date?->toDateString()] : null,
            'seat_list' => $r->requestSeats->map(fn (RequestSeat $s) => self::seat($s))->values()->all(),
        ];
    }

    public static function seat(RequestSeat $s): array
    {
        return [
            'no' => (int) $s->seat_no,
            'status' => $s->status,
            'status_label' => RequestSeat::STATUSES[$s->status] ?? $s->status,
            'participant_name' => $s->participant_name,
            'participant_email' => $s->participant_email,
            'unit' => self::unit($s->unit),
            'assignment_method' => $s->assignment_method,
            'enrollment_id' => $s->enrollment_id,
            'assigned_at' => $s->assigned_at?->toIso8601String(),
        ];
    }

    /**
     * Enrollment bersponsor. `progress_visible=false` (peran tanpa view_progress / peserta sudah keluar & exit_access ≠ full)
     * → angka kehadiran disembunyikan.
     */
    public static function enrollment(Enrollment $e, string $employmentStatus, ?EmploymentRelationship $employment, bool $progressVisible, bool $certificateVisible = true): array
    {
        $seat = $e->relationLoaded('requestSeat') ? $e->requestSeat : null;
        $c = $certificateVisible ? $e->certificate : null;

        return [
            'id' => $e->id,
            'type' => $e->type,
            'status' => $e->status,
            'participant' => [
                'name' => $e->member?->user?->name ?? $e->participant_name,
                'member_number' => $e->member?->member_number,
                'employee_id' => $employment?->employee_id,
                'employment_status' => $employmentStatus,
            ],
            'unit' => self::unit($e->sponsorUnit),
            'program' => $e->schedule?->package ? ['slug' => $e->schedule->package->slug, 'title' => $e->schedule->package->title] : null,
            'schedule' => self::schedule($e->schedule),
            'request' => $seat ? ['code' => $seat->request?->code, 'seat_no' => (int) $seat->seat_no] : null,
            'progress_visible' => $progressVisible,
            'attendance_percent' => $progressVisible ? $e->attendancePercent() : null,
            'final_score' => $progressVisible && $e->final_score !== null ? (float) $e->final_score : null,
            'certificate' => $c ? self::certificateStatus($c) : null,
            'enrolled_at' => $e->enrolled_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }

    /** Status sertifikat dalam konteks sponsor (§20.11 "limited status"): nomor hanya bila terbit. */
    public static function certificateStatus(Certificate $c): array
    {
        $issued = $c->status === 'issued';

        return [
            'status' => $c->status,
            'number' => $issued ? $c->number : null,
            'issued_at' => $c->issued_at?->toDateString(),
            'valid_until' => $issued ? $c->valid_until?->toDateString() : null,
            'verification_url' => $issued ? $c->verificationUrl() : null,
        ];
    }

    public static function employee(EmploymentRelationship $e): array
    {
        return [
            'code' => $e->code,
            'employee_id' => $e->employee_id,
            'name' => $e->member?->user?->name,
            'member_number' => $e->member?->member_number,
            'position' => $e->position,
            'unit' => self::unit($e->unit),
            'status' => $e->status,
            'start_date' => $e->start_date?->toDateString(),
            'end_date' => $e->end_date?->toDateString(),
            'end_reason' => $e->end_reason,
            'source' => $e->source,
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }

    public static function invoice(Invoice $i, bool $detail = false): array
    {
        $base = [
            'number' => $i->invoice_number,
            'status' => $i->status,
            'status_label' => $i->statusLabel(),
            'source' => $i->training_request_id
                ? ['type' => 'training_request', 'code' => $i->trainingRequest?->code]
                : ['type' => 'registration', 'code' => $i->registration?->registration_number],
            'subtotal' => (float) $i->subtotal,
            'discount' => (float) $i->discount,
            'tax_rate' => (float) $i->tax_rate,
            'tax_amount' => (float) $i->tax_amount,
            'total' => (float) $i->total,
            'amount_paid' => (float) $i->amount_paid,
            'amount_refunded' => (float) $i->amount_refunded,
            'outstanding' => $i->outstanding(),
            'currency' => 'IDR',
            'issued_at' => $i->issued_at?->toIso8601String(),
            'due_date' => $i->due_date?->toDateString(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
        if (! $detail) {
            return $base;
        }

        return $base + [
            'items' => $i->items->map(fn ($it) => ['description' => $it->description, 'quantity' => (float) $it->quantity, 'unit_price' => (float) $it->unit_price, 'amount' => (float) $it->amount])->values()->all(),
            'transactions' => $i->transactions->map(fn (PaymentTransaction $t) => [
                'code' => $t->code, 'method' => $t->method, 'amount' => (float) $t->amount,
                'amount_received' => $t->amount_received !== null ? (float) $t->amount_received : null,
                'status' => $t->status, 'status_label' => PaymentTransaction::STATUSES[$t->status] ?? $t->status,
                'transfer_date' => $t->transfer_date?->toDateString(), 'submitted_at' => $t->submitted_at?->toIso8601String(), 'verified_at' => $t->verified_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public static function delivery(NotificationDelivery $d): array
    {
        return [
            'id' => $d->id,
            'event' => $d->event?->key,
            'channel' => $d->channel,
            'status' => $d->status,
            'status_label' => $d->statusLabel(),
            'recipient_name' => $d->recipient_name,
            'title' => $d->content['title'] ?? null,
            'attempts' => (int) $d->attempts,
            'scheduled_at' => $d->scheduled_at?->toIso8601String(),
            'sent_at' => $d->sent_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'read_at' => $d->read_at?->toIso8601String(),
            'failed_at' => $d->failed_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
