<?php

use App\Http\Middleware\TransformApiCaseMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::group([], __DIR__.'/../routes/billing.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            TransformApiCaseMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/v1/subscriptions*', 'api/v1/items/*/valuation')) {
                return response()->json(['code' => 'UNAUTHENTICATED', 'message' => 'UNAUTHENTICATED'], 401);
            }
        });
    })->create();
