<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — PPTI VMES ITMS
|--------------------------------------------------------------------------
|
| Semua endpoint publik dan terautentikasi berada di prefix /api/v1
| (lihat task.md §1D untuk daftar endpoint publik yang akan ditambahkan).
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

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', fn (Request $request) => $request->user())->name('user');
    });
});
