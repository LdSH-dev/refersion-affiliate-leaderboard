<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware()
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers JSON, even when the client didn't send Accept: application/json.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every other api/* error (unknown route, wrong method, throttling, a bug) gets the same
        // { error: { code, message } } shape, without internals even when APP_DEBUG is on.
        // Refersion failures render themselves first (RefersionException::render).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof HttpResponseException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $code = match (true) {
                $status === 404 => 'not_found',
                $status === 405 => 'method_not_allowed',
                $status === 429 => 'too_many_requests',
                $status >= 500 => 'internal_error',
                default => 'http_'.$status,
            };
            $message = $status >= 500 ? 'Internal server error.' : (Response::$statusTexts[$status] ?? 'Error').'.';

            return new JsonResponse(
                ['error' => ['code' => $code, 'message' => $message]],
                $status,
                $e instanceof HttpExceptionInterface ? $e->getHeaders() : [],
            );
        });
    })->create();
