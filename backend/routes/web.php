<?php

use App\Http\Controllers\Admin\ReportExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Ekspor laporan admin (CSV/XLSX) — dilindungi sesi panel Filament (redirect ke /admin/login bila belum masuk)
Route::get('/admin/export/{type}.{format}', ReportExportController::class)
    ->middleware(['web', 'auth'])
    ->where(['type' => '[a-z_]+', 'format' => 'csv|xlsx'])
    ->name('admin.export');
