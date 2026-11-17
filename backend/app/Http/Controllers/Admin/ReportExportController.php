<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AnalyticsService;
use App\Services\ReportService;
use App\Support\SpreadsheetDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /admin/export/{type}.{format}?from=&to=: ekspor laporan CSV/XLSX (sesi panel admin).
 * Jenis eksekutif (ReportService::EXECUTIVE_TYPES) menerima seluruh filter Dashboard Eksekutif dan butuh izin analytics.view.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $type, string $format, ReportService $reports): StreamedResponse
    {
        $executive = array_key_exists($type, ReportService::EXECUTIVE_TYPES);
        $members = array_key_exists($type, ReportService::MEMBER_TYPES);
        abort_unless($executive || $members || array_key_exists($type, ReportService::TYPES), 404);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $user = $request->user();
        abort_unless($user?->isActive(), 403);
        $allowed = match (true) {
            $executive => $user->hasPermission('analytics.view') || $user->isSuperAdmin(),
            // Data pribadi peserta/alumni: hanya peran berizin members.view
            $members => $user->hasPermission('members.view') || $user->isSuperAdmin(),
            default => $user->hasPermission('reports.view') || $user->isAdmin(),
        };
        abort_unless($allowed, 403);

        $filters = match (true) {
            $executive => array_filter($request->only(AnalyticsService::FILTER_KEYS), fn ($v) => filled($v)),
            $members => array_filter($request->only($type === 'alumni' ? ['year', 'program'] : ['status', 'company', 'program']), fn ($v) => filled($v) && is_scalar($v)),
            default => [],
        };
        $data = $reports->dataset($type, $request->string('from')->toString() ?: null, $request->string('to')->toString() ?: null, $filters);
        $file = 'laporan-'.$type.'-'.now()->format('Ymd-Hi').'.'.$format;
        AuditLog::record('report.exported', null, null, ['type' => $type, 'format' => $format, 'rows' => count($data['rows'])] + ($filters ? ['filters' => $filters] : []));

        return SpreadsheetDownload::make($data, $file, $format);
    }
}
