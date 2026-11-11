<?php

use App\Http\Controllers\PublicApi\V1\CatalogController;
use App\Http\Controllers\PublicApi\V1\CertificateController;
use App\Http\Controllers\PublicApi\V1\RegistrationController;
use App\Support\PublicApiSpec;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Publik v1 (Phase 5) — /api/public/v1
|--------------------------------------------------------------------------
|
| Kontrak integrasi untuk pihak ketiga (mitra, korporasi, principal, agregator), terpisah dari API
| internal frontend (/api/v1). Autentikasi API key per klien (X-Api-Key), scope, rate limit, log,
| webhook keluar. Spesifikasi OpenAPI: GET /api/public/v1/openapi.json, dokumentasi: /docs/api.
|
*/

Route::get('/openapi.json', fn () => response()->json(PublicApiSpec::toArray(), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->name('openapi');

Route::middleware('api.client')->group(function (): void {
    Route::get('/', [CatalogController::class, 'client'])->name('client');

    Route::middleware('api.scope:catalog.read')->group(function (): void {
        Route::get('/categories', [CatalogController::class, 'categories'])->name('categories');
        Route::get('/programs', [CatalogController::class, 'programs'])->name('programs.index');
        Route::get('/programs/{slug}', [CatalogController::class, 'program'])->name('programs.show');
        Route::get('/schedules', [CatalogController::class, 'schedules'])->name('schedules.index');
        Route::get('/schedules/{code}', [CatalogController::class, 'schedule'])->name('schedules.show');
    });

    Route::post('/registrations', [RegistrationController::class, 'store'])->middleware('api.scope:registrations.write')->name('registrations.store');
    Route::middleware('api.scope:registrations.read')->group(function (): void {
        Route::get('/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
        Route::get('/registrations/{number}', [RegistrationController::class, 'show'])->name('registrations.show');
    });

    Route::get('/certificates/{number}', [CertificateController::class, 'verify'])->middleware('api.scope:certificates.verify')->name('certificates.verify');
});
