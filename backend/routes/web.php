<?php

use App\Http\Controllers\Admin\CertificatePdfController;
use App\Http\Controllers\Admin\ReportExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Ekspor laporan admin (CSV/XLSX) — dilindungi sesi panel Filament (redirect ke /admin/login bila belum masuk)
Route::get('/admin/export/{type}.{format}', ReportExportController::class)
    ->middleware(['web', 'auth'])
    ->where(['type' => '[a-z_-]+', 'format' => 'csv|xlsx'])
    ->name('admin.export');

// PDF sertifikat (pratinjau/unduh) & contoh template — sesi panel admin
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/admin/certificates/{certificate}/pdf', [CertificatePdfController::class, 'certificate'])->whereNumber('certificate')->name('admin.certificates.pdf');
    Route::get('/admin/certificate-templates/{template}/preview', [CertificatePdfController::class, 'template'])->whereNumber('template')->name('admin.certificate_templates.preview');
});

// Dokumentasi API Publik (Redoc membaca /api/public/v1/openapi.json)
Route::get('/docs/api', fn () => view('docs.api', ['spec' => route('public-api.v1.openapi')]))->name('docs.api');
