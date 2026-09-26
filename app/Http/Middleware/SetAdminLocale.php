<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetAdminLocale
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_LOCALES = ['en', 'ar', 'de', 'bg'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->session()->get('dashboard_locale', 'en');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = 'en';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
