<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Validation failed.',
                    'details' => $e->errors(),
                ],
                'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            ], 422);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => 'Resource not found.',
                    'details' => [],
                ],
                'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            ], 404);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $code = $status >= 500 ? 'internal_error' : 'request_error';

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $status >= 500 ? 'Internal server error.' : $e->getMessage(),
                    'details' => [],
                ],
                'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            ], $status);
        });
    })->create();
