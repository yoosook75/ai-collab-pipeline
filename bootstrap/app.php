<?php

use App\Http\Middleware\BlockSuspiciousIps;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Security\ErrorSpikeMonitor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        // Appended to the web group so it runs after the session starts (admin bypass).
        $middleware->appendToGroup('web', BlockSuspiciousIps::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $e): void {
            try {
                app(ErrorSpikeMonitor::class)->record($e);
            } catch (Throwable) {
                // Never let spike monitoring break error reporting.
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
