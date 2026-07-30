<?php

use App\Http\Middleware\CorrelateRequest;
use App\Http\Middleware\ResolveTenant;
use App\Support\CorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelateRequest::class);
        $middleware->alias(['tenant' => ResolveTenant::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response): Response {
            if (Context::has('request_id')) {
                $response->headers->set(CorrelationId::HEADER, (string) Context::get('request_id'));
            }

            return $response;
        });
    })->create();
