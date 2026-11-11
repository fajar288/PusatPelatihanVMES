<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Services\CertificateService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Edit draf + alur status: ajukan validasi → terbitkan (PDF+QR, email) → cabut / ganti; buat ulang PDF; unduh. */
class EditCertificate extends EditRecord
{
    protected static string $resource = CertificateResource::class;

    protected function getHeaderActions(): array
    {
        $run = function (callable $fn, string $ok): void {
            try {
                $fn();
                Notification::make()->title($ok)->success()->send();
            } catch (ValidationException $e) {
                Notification::make()->title(collect($e->errors())->flatten()->implode(' '))->danger()->send();
            }
            $this->refreshFormData(['status', 'issued_at', 'valid_until', 'pdf_path', 'notes']);
        };

        return [
            Action::make('pdf')->label('Pratinjau PDF')->icon('heroicon-o-eye')->color('gray')->url(fn (Certificate $record) => route('admin.certificates.pdf', $record->id))->openUrlInNewTab(),
            Action::make('download')->label('Unduh PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')->visible(fn (Certificate $record) => $record->isIssued())
                ->url(fn (Certificate $record) => route('admin.certificates.pdf', ['certificate' => $record->id, 'download' => 1]))->openUrlInNewTab(),
            Action::make('submit')->label('Ajukan validasi')->icon('heroicon-o-paper-airplane')->requiresConfirmation()
                ->visible(fn (Certificate $record) => $record->status === 'draft')
                ->action(fn (Certificate $record, CertificateService $svc) => $run(fn () => $svc->submit($record, auth()->user()), 'Diajukan untuk validasi pejabat berwenang')),
            Action::make('issue')->label('Terbitkan')->icon('heroicon-o-check-badge')->color('success')->requiresConfirmation()
                ->modalDescription('Sertifikat akan diberi status Terbit, PDF dibuat, dan email dikirim ke penerima. Data tidak dapat diubah lagi setelah terbit.')
                ->visible(fn (Certificate $record) => $record->isEditable() && CertificateResource::canIssue())
                ->action(fn (Certificate $record, CertificateService $svc) => $run(fn () => $svc->issue($record, auth()->user()), 'Sertifikat diterbitkan')),
            Action::make('reject')->label('Kembalikan ke draf')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->visible(fn (Certificate $record) => $record->status === 'pending_validation' && CertificateResource::canIssue())
                ->schema([Textarea::make('reason')->label('Alasan')->required()->rows(2)])
                ->action(fn (array $data, Certificate $record, CertificateService $svc) => $run(fn () => $svc->reject($record, auth()->user(), $data['reason']), 'Dikembalikan ke draf')),
            Action::make('regenerate')->label('Buat ulang PDF')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn (Certificate $record) => $record->isIssued() && CertificateResource::canIssue())
                ->action(fn (Certificate $record, CertificateService $svc) => $run(fn () => $svc->storePdf($record, auth()->user()), 'PDF dibuat ulang')),
            Action::make('revoke')->label('Cabut')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (Certificate $record) => $record->isIssued() && CertificateResource::canIssue())
                ->schema([Textarea::make('reason')->label('Alasan pencabutan')->required()->rows(2)])
                ->action(fn (array $data, Certificate $record, CertificateService $svc) => $run(fn () => $svc->revoke($record, auth()->user(), $data['reason']), 'Sertifikat dicabut')),
            Action::make('replace')->label('Ganti (terbitkan ulang)')->icon('heroicon-o-document-duplicate')->color('warning')
                ->visible(fn (Certificate $record) => in_array($record->status, ['issued', 'revoked'], true) && CertificateResource::canIssue())
                ->schema([
                    TextInput::make('recipient_name')->label('Nama penerima (baru)')->default(fn (Certificate $record) => $record->recipient_name)->required()->maxLength(255),
                    Textarea::make('reason')->label('Alasan penggantian')->required()->rows(2),
                ])
                ->action(function (array $data, Certificate $record, CertificateService $svc): void {
                    try {
                        $new = $svc->replace($record, auth()->user(), $data['reason'], ['recipient_name' => $data['recipient_name']]);
                        Notification::make()->title("Draf pengganti {$new->number} dibuat")->success()->send();
                        $this->redirect(CertificateResource::getUrl('edit', ['record' => $new]));
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                    }
                }),
            DeleteAction::make()->visible(fn (Certificate $record) => $record->status === 'draft'),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Certificate $record */
        if (! $record->isEditable()) {
            Notification::make()->title('Sertifikat yang sudah terbit/dicabut/diganti tidak dapat diubah. Gunakan "Ganti" untuk menerbitkan ulang.')->danger()->send();
            $this->halt();
        }
        $before = $record->only(array_keys($data));
        $record->update($data);
        AuditLog::record('certificate.updated', $record, $before, $data);

        return $record;
    }
}
