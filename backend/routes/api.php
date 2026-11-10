<?php

use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SearchController;
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

    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('/posts/{slug}', [PostController::class, 'show'])->name('posts.show');

    Route::get('/partners', [ContentController::class, 'partners'])->name('partners');
    Route::get('/testimonials', [ContentController::class, 'testimonials'])->name('testimonials');
    Route::get('/galleries', [ContentController::class, 'galleries'])->name('galleries');
    Route::get('/categories', [ContentController::class, 'categories'])->name('categories');
    Route::get('/menus', [ContentController::class, 'menus'])->name('menus');
    Route::get('/settings', [ContentController::class, 'settings'])->name('settings');
    Route::get('/pages/{slug}', [ContentController::class, 'page'])->name('pages.show');

    // ---- Terautentikasi ----
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', fn (Request $request) => $request->user())->name('user');
    });
});
