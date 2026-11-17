<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTrainer;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TrainingSchedule;
use App\Services\TrainerReportService;
use App\Support\SpreadsheetDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Laporan portal trainer: ringkasan kelas yang ditugaskan dan ekspor rincian peserta per kelas. */
class TrainerReportController extends Controller
{
    use ResolvesTrainer;

    public function __construct(private readonly TrainerReportService $reports) {}

    /** GET /trainer/reports?from=&to=&status= */
    public function index(Request $request): JsonResponse
    {
        $trainer = $this->trainer($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(TrainingSchedule::STATUSES)],
        ]);

        return response()->json(['data' => $this->reports->classes($trainer, $filters), 'filters' => $filters]);
    }

    /** GET /trainer/reports/{code}/export.{format}: rincian per peserta satu kelas (CSV/XLSX). */
    public function export(Request $request, string $code, string $format): StreamedResponse
    {
        abort_unless(in_array($format, SpreadsheetDownload::FORMATS, true), 404);
        $schedule = $this->classOf($this->trainer($request), $code);
        $data = $this->reports->participantDataset($schedule);
        AuditLog::record('trainer.report_exported', $schedule, null, ['class' => $schedule->code, 'format' => $format, 'rows' => count($data['rows'])]);
        $file = 'laporan-kelas-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $schedule->code).'-'.now()->format('Ymd-Hi').'.'.$format;

        return SpreadsheetDownload::make($data, $file, $format);
    }
}
