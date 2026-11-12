<?php

namespace App\Filament\Resources\Reconciliation\Pages;

use App\Filament\Resources\Reconciliation\ReconciliationResource;
use App\Filament\Support\EnterpriseFields;
use App\Filament\Widgets\ReconciliationStats;
use App\Models\PaymentAccount;
use App\Services\ReconciliationService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListStatementLines extends ListRecords
{
    protected static string $resource = ReconciliationResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ReconciliationStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('Unggah mutasi rekening')->icon('heroicon-o-arrow-up-tray')
                ->modalDescription('CSV / XLSX dari internet banking. Kolom wajib: tanggal dan nominal/kredit; opsional: keterangan, referensi, debit. Baris uang keluar diabaikan, baris yang sudah pernah diimpor dilewati.')
                ->schema([
                    FileUpload::make('file')->label('Berkas mutasi')->required()->disk('local')->directory('bank-statements')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(5120)->storeFileNamesIn('file_name'),
                    Select::make('payment_account_id')->label('Rekening')->options(PaymentAccount::query()->orderBy('sort')->get()->mapWithKeys(fn (PaymentAccount $a) => [$a->id => $a->summary()])->all()),
                ])
                ->action(function (array $data, Action $action): void {
                    $path = Storage::disk('local')->path($data['file']);
                    $result = EnterpriseFields::attempt($action, fn () => app(ReconciliationService::class)->import($path, $data['file_name'] ?? basename($data['file']), auth()->user(), $data['payment_account_id'] ?? null));
                    Storage::disk('local')->delete($data['file']);
                    if ($result) {
                        $errors = $result['errors'] ? ' '.count($result['errors']).' baris dilewati: '.implode(' ', array_slice($result['errors'], 0, 3)) : '';
                        Notification::make()->title("{$result['imported']} baris diimpor, {$result['matched']} cocok otomatis")
                            ->body(($result['skipped'] ? "{$result['skipped']} baris sudah pernah diimpor." : '').$errors)->success()->send();
                    }
                }),
        ];
    }
}
