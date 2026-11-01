<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitBillingPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(strlen($request->getContent()) > 1048576, 413);

        return $next($request);
    }
}
