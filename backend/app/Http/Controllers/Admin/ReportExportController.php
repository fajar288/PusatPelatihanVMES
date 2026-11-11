<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AnalyticsService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /admin/export/{type}.{format}?from=&to= — ekspor laporan CSV/XLSX (sesi panel admin).
 * Jenis eksekutif (ReportService::EXECUTIVE_TYPES) menerima seluruh filter Dashboard Eksekutif dan butuh izin analytics.view.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $type, string $format, ReportService $reports): StreamedResponse
    {
        $executive = array_key_exists($type, ReportService::EXECUTIVE_TYPES);
        abort_unless($executive || array_key_exists($type, ReportService::TYPES), 404);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $user = $request->user();
        $allowed = $executive
            ? ($user?->hasPermission('analytics.view') || $user?->isSuperAdmin())
            : ($user?->hasPermission('reports.view') || $user?->isAdmin());
        abort_unless($allowed, 403);

        $filters = $executive ? array_filter($request->only(AnalyticsService::FILTER_KEYS), fn ($v) => filled($v)) : [];
        $data = $reports->dataset($type, $request->string('from')->toString() ?: null, $request->string('to')->toString() ?: null, $filters);
        $file = 'laporan-'.$type.'-'.now()->format('Ymd-Hi').'.'.$format;
        AuditLog::record('report.exported', null, null, ['type' => $type, 'format' => $format, 'rows' => count($data['rows'])] + ($filters ? ['filters' => $filters] : []));

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
