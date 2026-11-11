<?php

use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Middleware\RequireApiScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // API Publik untuk integrasi pihak ketiga (API key), terpisah dari API internal frontend
            Route::middleware('api')->prefix('api/public/v1')->name('public-api.v1.')->group(base_path('routes/public-api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: request dari FRONTEND_URL (SANCTUM_STATEFUL_DOMAINS) memakai cookie session
        $middleware->statefulApi();
        // Route web non-panel (mis. ekspor laporan) → tamu diarahkan ke login panel admin
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->alias([
            'api.client' => AuthenticateApiClient::class,
            'api.scope' => RequireApiScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API Publik: format galat seragam {"error": {"code", "message", "fields"?}}
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/public/*')) {
                return response()->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->errors()]], $e->status);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/public/*')) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    404 => 'not_found', 405 => 'method_not_allowed', 429 => 'rate_limited', 403 => 'forbidden', 401 => 'unauthenticated', default => 'http_error',
                };

                return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage() ?: 'Permintaan tidak dapat diproses.']], $status, $e->getHeaders());
            }
        });
    })->create();
