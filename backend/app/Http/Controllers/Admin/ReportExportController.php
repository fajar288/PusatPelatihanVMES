<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\ReportService;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GET /admin/export/{type}.{format}?from=&to= — ekspor laporan CSV/XLSX (sesi panel admin). */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $type, string $format, ReportService $reports): StreamedResponse
    {
        abort_unless(array_key_exists($type, ReportService::TYPES), 404);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        abort_unless($request->user()?->hasPermission('reports.view') || $request->user()?->isAdmin(), 403);

        $data = $reports->dataset($type, $request->string('from')->toString() ?: null, $request->string('to')->toString() ?: null);
        $file = 'laporan-'.$type.'-'.now()->format('Ymd-Hi').'.'.$format;
        AuditLog::record('report.exported', null, null, ['type' => $type, 'format' => $format, 'rows' => count($data['rows'])]);

        return response()->streamDownload(function () use ($data, $format): void {
            $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($data['headers']));
            foreach ($data['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', $row)));
            }
            $writer->close();
        }, $file, ['Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8']);
    }
}
