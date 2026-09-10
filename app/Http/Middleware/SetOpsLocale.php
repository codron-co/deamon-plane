<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetOpsLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('ops.locales', ['en', 'tr']);
        $candidate = $request->user()?->locale
            ?? $request->session()->get('locale');

        $locale = is_string($candidate) && in_array($candidate, $supported, true)
            ? $candidate
            : (string) config('app.locale', 'en');

        App::setLocale($locale);

        return $next($request);
    }
}
