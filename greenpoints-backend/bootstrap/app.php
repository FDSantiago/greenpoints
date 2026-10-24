<?php

use App\Integrations\SchoolRegistry\Exceptions\RegistryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The School Registry (System 2) is down, timed out, rejected our key or answered nonsense.
        // The details go to the log; the client gets a stable code. Never includes the API key.
        $exceptions->dontReport(RegistryException::class);
        $exceptions->render(function (RegistryException $e) {
            Log::warning('school_registry.unavailable', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
                'request_id' => $e->requestId,
            ]);

            return response()->json(['error' => [
                'code' => 'REGISTRY_UNAVAILABLE',
                'message' => 'The School Registry is unavailable right now. Please try again later.',
                'details' => ['request_id' => $e->requestId],
            ]], 503);
        });
    })->create();
