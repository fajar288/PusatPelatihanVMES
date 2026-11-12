<?php

use App\Http\Controllers\PublicApi\V1\CatalogController;
use App\Http\Controllers\PublicApi\V1\CertificateController;
use App\Http\Controllers\PublicApi\V1\Enterprise\AccountController;
use App\Http\Controllers\PublicApi\V1\Enterprise\EmployeeController;
use App\Http\Controllers\PublicApi\V1\Enterprise\EnrollmentController;
use App\Http\Controllers\PublicApi\V1\Enterprise\OrganizationController;
use App\Http\Controllers\PublicApi\V1\Enterprise\TrainingRequestController;
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

    Route::post('/registrations', [RegistrationController::class, 'store'])->middleware(['api.scope:registrations.write', 'api.idempotent:optional'])->name('registrations.store');
    Route::middleware('api.scope:registrations.read')->group(function (): void {
        Route::get('/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
        Route::get('/registrations/{number}', [RegistrationController::class, 'show'])->name('registrations.show');
    });

    Route::get('/certificates/{number}', [CertificateController::class, 'verify'])->middleware('api.scope:certificates.verify')->name('certificates.verify');

    /*
    | API enterprise (Roadmap 5E, PRD Roadmap 5 §7) — hanya klien terikat organisasi. Organisasi & pengguna perusahaan
    | yang diwakili berasal dari klien (bukan URL); peran & batas unitnya ikut membatasi. POST penting wajib Idempotency-Key.
    */
    Route::middleware('api.organization')->group(function (): void {
        Route::get('/corporate/organization', [OrganizationController::class, 'show'])->middleware('api.scope:organization.read')->name('corporate.organization');
        Route::get('/analytics', [OrganizationController::class, 'analytics'])->middleware('api.scope:analytics.read')->name('analytics');

        Route::middleware('api.scope:corporate.requests.read')->group(function (): void {
            Route::get('/corporate/requests', [TrainingRequestController::class, 'index'])->name('corporate.requests.index');
            Route::get('/corporate/requests/{code}', [TrainingRequestController::class, 'show'])->name('corporate.requests.show');
        });
        Route::middleware(['api.scope:corporate.requests.write', 'api.idempotent'])->group(function (): void {
            Route::post('/corporate/requests', [TrainingRequestController::class, 'store'])->name('corporate.requests.store');
            Route::post('/corporate/requests/{code}/{action}', [TrainingRequestController::class, 'transition'])
                ->whereIn('action', ['submit', 'accept-quote', 'approve', 'cancel'])->name('corporate.requests.transition');
        });
        Route::middleware(['api.scope:enrollments.write', 'api.idempotent'])->group(function (): void {
            Route::post('/corporate/requests/{code}/participants', [TrainingRequestController::class, 'assign'])->name('corporate.requests.participants');
            Route::post('/corporate/requests/{code}/seats/{no}/release', [TrainingRequestController::class, 'release'])->whereNumber('no')->name('corporate.requests.seats.release');
        });

        Route::middleware('api.scope:corporate.employees.read')->group(function (): void {
            Route::get('/corporate/employees', [EmployeeController::class, 'index'])->name('corporate.employees.index');
            Route::get('/corporate/employees/{code}', [EmployeeController::class, 'show'])->name('corporate.employees.show');
        });
        Route::middleware(['api.scope:corporate.employees.write', 'api.idempotent'])->group(function (): void {
            Route::post('/corporate/employees', [EmployeeController::class, 'store'])->name('corporate.employees.store');
            Route::post('/corporate/employees/{code}/end', [EmployeeController::class, 'end'])->name('corporate.employees.end');
        });

        Route::middleware('api.scope:enrollments.read')->group(function (): void {
            Route::get('/enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
            Route::get('/enrollments/{id}', [EnrollmentController::class, 'show'])->whereNumber('id')->name('enrollments.show');
        });
        Route::get('/progress', [EnrollmentController::class, 'progress'])->middleware('api.scope:progress.read')->name('progress');
        Route::get('/attendance', [EnrollmentController::class, 'attendance'])->middleware('api.scope:attendance.read')->name('attendance');
        Route::get('/assessments', [EnrollmentController::class, 'assessments'])->middleware('api.scope:assessments.read')->name('assessments');
        Route::get('/certificates', [EnrollmentController::class, 'certificates'])->middleware('api.scope:certificates.read')->name('certificates.index');

        Route::middleware('api.scope:payments.read')->group(function (): void {
            Route::get('/payments', [AccountController::class, 'payments'])->name('payments.index');
            Route::get('/payments/{number}', [AccountController::class, 'payment'])->name('payments.show');
        });
        Route::get('/notifications', [AccountController::class, 'notifications'])->middleware('api.scope:notifications.read')->name('notifications');
    });
});
