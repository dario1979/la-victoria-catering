<?php

namespace App\Http\Middleware;

use App\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final class CorrelateRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = CorrelationId::resolve($request->header(CorrelationId::HEADER));
        Context::add('request_id', $requestId);
        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(CorrelationId::HEADER, $requestId);

        return $response;
    }
}
