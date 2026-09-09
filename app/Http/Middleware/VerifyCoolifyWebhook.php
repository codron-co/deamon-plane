<?php

namespace App\Http\Middleware;

use App\Models\CoolifySetting;
use App\Support\CoolifyWebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyCoolifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = CoolifySetting::resolvedWebhookSecret();
        if ($secret === null) {
            Log::warning('Coolify webhook rejected: signing secret is not configured');

            abort(401, 'Unauthorized.');
        }

        $header = CoolifyWebhookSignature::headerFromRequest($request);
        if (! CoolifyWebhookSignature::matches($secret, $request->getContent(), $header)) {
            Log::warning('Coolify webhook rejected: invalid signature');

            abort(401, 'Unauthorized.');
        }

        return $next($request);
    }
}
