<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Enrollment;
use App\Services\CertificateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Buat draf sertifikat lewat CertificateService (nomor unik, kelayakan, audit). */
class CreateCertificate extends CreateRecord
{
    protected static string $resource = CertificateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $enrollment = Enrollment::query()->with('schedule.package')->findOrFail($data['enrollment_id']);
        $overrides = array_filter([
            'type' => $data['type'] ?? null,
            'template_id' => $data['template_id'] ?? null,
            'issued_at' => $data['issued_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return app(CertificateService::class)->createDraft($enrollment, auth()->user(), $overrides, (bool) ($data['force'] ?? false), $data['force_reason'] ?? null);
    }
}
