<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\TrainerController;
use App\Http\Controllers\Api\V1\WaitlistController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — PPTI VMES ITMS
|--------------------------------------------------------------------------
|
| Endpoint publik (task.md §1D) dikonsumsi frontend Next.js menggantikan src/data/*.
| Endpoint terautentikasi (portal/admin) ditambahkan di Phase 1E dst.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'version' => 'v1',
            'laravel' => app()->version(),
            'time' => now()->toIso8601String(),
        ]);
    })->name('health');

    // ---- Publik (CMS) ----
    Route::get('/home', HomeController::class)->name('home');
    Route::get('/search', SearchController::class)->name('search');

    Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
    Route::get('/packages/{slug}', [PackageController::class, 'show'])->name('packages.show');

    Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
    Route::get('/schedules/{schedule}', [ScheduleController::class, 'show'])->name('schedules.show');
    Route::post('/schedules/{schedule}/waitlist', [WaitlistController::class, 'store'])->middleware('throttle:10,1')->name('schedules.waitlist');

    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('/posts/{slug}', [PostController::class, 'show'])->name('posts.show');

    Route::get('/partners', [ContentController::class, 'partners'])->name('partners');
    Route::get('/testimonials', [ContentController::class, 'testimonials'])->name('testimonials');
    Route::get('/galleries', [ContentController::class, 'galleries'])->name('galleries');
    Route::get('/categories', [ContentController::class, 'categories'])->name('categories');
    Route::get('/menus', [ContentController::class, 'menus'])->name('menus');
    Route::get('/settings', [ContentController::class, 'settings'])->name('settings');
    Route::get('/pages/{slug}', [ContentController::class, 'page'])->name('pages.show');

    // ---- Pendaftaran publik (tamu atau member) & autentikasi (Phase 1E) ----
    Route::post('/registrations', [RegistrationController::class, 'store'])->middleware('throttle:10,1')->name('registrations.store');
    Route::prefix('auth')->name('auth.')->middleware('throttle:20,1')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])->name('login');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('reset');
    });

    // ---- Terautentikasi (portal member) ----
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/user', fn (Request $request) => $request->user())->name('user');
        Route::get('/me', [MeController::class, 'show'])->name('me.show');
        Route::patch('/me', [MeController::class, 'update'])->name('me.update');
        Route::get('/me/registrations', [MeController::class, 'registrations'])->name('me.registrations');
        Route::get('/me/registrations/{registration}', [MeController::class, 'registration'])->name('me.registration');
        Route::post('/me/registrations/{registration}/cancel', [MeController::class, 'cancel'])->name('me.registration.cancel');
        Route::post('/me/registrations/{registration}/payment-proof', [MeController::class, 'paymentProof'])->name('me.registration.proof');
        Route::get('/me/payments', [MeController::class, 'payments'])->name('me.payments');
        Route::get('/me/notifications', [MeController::class, 'notifications'])->name('me.notifications');
        Route::get('/me/enrollments', [MeController::class, 'enrollments'])->name('me.enrollments');

        // Portal trainer (PRD §5.7) — akun dengan profil trainer aktif
        Route::prefix('trainer')->name('trainer.')->group(function (): void {
            Route::get('/profile', [TrainerController::class, 'profile'])->name('profile');
            Route::patch('/profile', [TrainerController::class, 'updateProfile'])->name('profile.update');
            Route::get('/classes', [TrainerController::class, 'classes'])->name('classes');
            Route::get('/classes/{code}', [TrainerController::class, 'show'])->name('classes.show');
            Route::post('/classes/{code}/sessions/{no}/attendance', [TrainerController::class, 'attendance'])->name('classes.attendance');
        });
    });
});
