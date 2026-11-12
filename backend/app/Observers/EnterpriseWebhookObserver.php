<?php

namespace App\Observers;

use App\Models\ApiClient;
use App\Models\Certificate;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\TrainingRequest;
use App\Services\CorporateAccess;
use App\Services\CorporateReporting;
use App\Services\WebhookService;
use App\Support\Enterprise\EnterpriseApiPresenter;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Webhook enterprise (Roadmap 5E) untuk klien API yang terikat organisasi: perubahan Corporate Training Request,
 * enrollment bersponsor (dibuat, status, transfer sponsor), hubungan kerja, invoice, dan sertifikat enrollment bersponsor.
 * Dijalankan setelah transaksi commit; nilai lama diambil dari getPrevious(). Payload memakai EnterpriseApiPresenter yang
 * sama dengan API (tanpa angka progres — ambil lewat API bila perlu). Peserta yang sudah keluar dari organisasi dengan
 * kebijakan exit_access=none tidak lagi dikirim.
 */
class EnterpriseWebhookObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Model $model): void
    {
        if ($model instanceof Enrollment && $model->isCorporate() && $model->sponsor_organization_id) {
            $this->enrollment($model, 'enrollment.created', $model->sponsor_organization_id);
        } elseif ($model instanceof EmploymentRelationship) {
            $this->employee($model, null);
        } elseif ($model instanceof Invoice) {
            $this->invoice($model, created: true);   // invoice baru diterbitkan (previous_status null)
        }
    }

    public function updated(Model $model): void
    {
        match (true) {
            $model instanceof TrainingRequest => $this->trainingRequest($model),
            $model instanceof Enrollment => $this->enrollmentUpdated($model),
            $model instanceof EmploymentRelationship => $model->wasChanged('status') ? $this->employee($model, $model->getPrevious()['status'] ?? null) : null,
            $model instanceof Invoice => $this->invoice($model),
            $model instanceof Certificate => $this->certificate($model),
            default => null,
        };
    }

    private function trainingRequest(TrainingRequest $r): void
    {
        if (! $r->wasChanged('status') || ! $this->subscribed($r->organization_id)) {
            return;
        }
        $r->loadMissing(['package', 'schedule.package', 'unit']);
        $data = Arr::except(EnterpriseApiPresenter::trainingRequest($r, null), ['quote']) + ['previous_status' => $r->getPrevious()['status'] ?? null];
        $this->hooks()->emitToOrganization($r->organization_id, 'training_request.status_changed', $data, $r->unit_id);
    }

    private function enrollmentUpdated(Enrollment $e): void
    {
        if ($e->wasChanged('sponsor_organization_id') || $e->wasChanged('type')) {
            $old = $e->getPrevious()['sponsor_organization_id'] ?? null;
            if ($old && (int) $old !== (int) $e->sponsor_organization_id) {
                // organisasi lama hanya diberi tahu bahwa enrollment keluar dari scope-nya (tanpa sponsor baru, §20.8)
                $this->hooks()->emitToOrganization((int) $old, 'enrollment.sponsorship_transferred', [
                    'id' => $e->id, 'direction' => 'out', 'transferred_at' => now()->toIso8601String(),
                ], $e->getPrevious()['sponsor_unit_id'] ?? null);
            }
            if ($e->isCorporate() && $e->sponsor_organization_id && (int) $old !== (int) $e->sponsor_organization_id) {
                $this->enrollment($e, 'enrollment.sponsorship_transferred', $e->sponsor_organization_id, ['direction' => 'in', 'transferred_at' => now()->toIso8601String()]);
            }

            return;
        }
        if ($e->wasChanged('status') && $e->isCorporate() && $e->sponsor_organization_id) {
            $this->enrollment($e, 'enrollment.status_changed', $e->sponsor_organization_id, ['previous_status' => $e->getPrevious()['status'] ?? null]);
        }
    }

    private function enrollment(Enrollment $e, string $event, int $orgId, array $extra = []): void
    {
        if (! $this->subscribed($orgId) || $this->hiddenAfterExit($e)) {
            return;
        }
        $e->loadMissing(['member.user', 'schedule.package', 'certificate', 'sponsorUnit', 'requestSeat.request']);
        $employment = $e->member_id ? EmploymentRelationship::query()->where('organization_id', $orgId)->where('member_id', $e->member_id)->latest('id')->first() : null;
        $status = app(CorporateReporting::class)->employmentStatus($e, $employment?->isActive() ? $employment : null);
        $data = EnterpriseApiPresenter::enrollment($e, $status, $employment, progressVisible: false) + $extra;
        $this->hooks()->emitToOrganization($orgId, $event, $data, $e->sponsor_unit_id);
    }

    private function employee(EmploymentRelationship $e, ?string $previous): void
    {
        if (! $this->subscribed($e->organization_id)) {
            return;
        }
        $e->loadMissing(['member.user', 'unit']);
        $this->hooks()->emitToOrganization($e->organization_id, 'employee.status_changed', EnterpriseApiPresenter::employee($e) + ['previous_status' => $previous], $e->unit_id);
    }

    private function invoice(Invoice $i, bool $created = false): void
    {
        if (! $i->organization_id || (! $created && ! $i->wasChanged('status')) || ! $this->subscribed($i->organization_id)) {
            return;
        }
        $previous = $created ? null : ($i->getPrevious()['status'] ?? null);
        $i = $i->fresh(['registration', 'trainingRequest']);   // total final (item & pajak dihitung dalam transaksi yang sama)
        $this->hooks()->emitToOrganization($i->organization_id, 'invoice.status_changed', EnterpriseApiPresenter::invoice($i) + ['previous_status' => $previous], $i->trainingRequest?->unit_id);
    }

    /** Sertifikat dari enrollment bersponsor. Klien yang sudah menerimanya lewat pendaftaran API-nya sendiri tidak dikirimi dua kali. */
    private function certificate(Certificate $c): void
    {
        if (! $c->wasChanged('status') || ! in_array($c->status, ['issued', 'revoked'], true)) {
            return;
        }
        $e = $c->enrollment;
        if (! $e || ! $e->isCorporate() || ! $e->sponsor_organization_id || ! $this->subscribed($e->sponsor_organization_id) || $this->hiddenAfterExit($e)) {
            return;
        }
        $e->loadMissing(['member.user', 'schedule.package', 'registration']);
        $data = [
            'number' => $c->number, 'status' => $c->status, 'type' => $c->type, 'recipient_name' => $c->recipient_name,
            'program' => $c->program_title, 'class_code' => $c->class_code,
            'issued_at' => $c->issued_at?->toDateString(), 'valid_until' => $c->valid_until?->toDateString(), 'revoked_at' => $c->revoked_at?->toDateString(),
            'verification_url' => $c->verificationUrl(),
            'registration_number' => $e->registration?->registration_number, 'external_ref' => $e->registration?->external_ref,
            'enrollment_id' => $e->id, 'member_number' => $e->member?->member_number,
        ];
        $this->hooks()->emitToOrganization($e->sponsor_organization_id, 'certificate.'.$c->status, $data, $e->sponsor_unit_id, $e->registration?->api_client_id);
    }

    /** Ada klien API aktif ber-webhook di organisasi ini? (hindari menyusun payload sia-sia) */
    private function subscribed(int $organizationId): bool
    {
        return ApiClient::query()->where('organization_id', $organizationId)->where('is_active', true)->whereNotNull('webhook_url')->exists();
    }

    private function hiddenAfterExit(Enrollment $e): bool
    {
        return $e->sponsor?->exitAccess() === 'none' && app(CorporateAccess::class)->hasExited($e);
    }

    private function hooks(): WebhookService
    {
        return app(WebhookService::class);
    }
}
