<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConvertOpsAjaxRedirect
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->expectsJson() || $response instanceof JsonResponse) {
            return $response;
        }

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $error = $request->session()->pull('error');
        $warning = $request->session()->pull('warning');
        $status = $request->session()->pull('status');
        $hasError = is_string($error) && $error !== '';
        $hasWarning = is_string($warning) && $warning !== '';
        $message = $hasError
            ? $error
            : ($hasWarning ? $warning : (is_string($status) ? $status : ''));

        $json = response()->json([
            'ok' => ! $hasError,
            'message' => $message,
            'type' => $hasError ? 'error' : ($hasWarning ? 'warning' : 'status'),
            'redirect' => $response->getTargetUrl(),
        ]);

        foreach ($response->headers->getCookies() as $cookie) {
            $json->withCookie($cookie);
        }

        return $json;
    }
}
