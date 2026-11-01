<?php

namespace App\Services\Subscriptions;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

class GoogleHttpTransport
{
    public function __invoke(RequestInterface $request): Response
    {
        $response = Http::connectTimeout(5)->timeout(15)->withHeaders($request->getHeaders())
            ->withBody((string) $request->getBody(), $request->getHeaderLine('Content-Type'))
            ->send($request->getMethod(), (string) $request->getUri());

        return new Response($response->status(), $response->headers(), $response->body());
    }
}
