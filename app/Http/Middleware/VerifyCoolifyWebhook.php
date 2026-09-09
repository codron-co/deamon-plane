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
        $secrets = CoolifySetting::resolvedWebhookSecrets();
        if ($secrets === []) {
            Log::warning('Coolify webhook rejected: signing secret is not configured');

            abort(401, 'Unauthorized.');
        }

        $header = CoolifyWebhookSignature::headerFromRequest($request);
        if ($header !== null) {
            foreach ($secrets as $secret) {
                if (CoolifyWebhookSignature::matches($secret, $request->getContent(), $header)) {
                    return $next($request);
                }
            }

            Log::warning('Coolify webhook rejected: invalid signature');

            abort(401, 'Unauthorized.');
        }

        $token = CoolifyWebhookSignature::queryTokenFromRequest($request);
        foreach ($secrets as $secret) {
            if (CoolifyWebhookSignature::queryTokenMatches($secret, $token)) {
                return $next($request);
            }
        }

        Log::warning('Coolify webhook rejected: invalid signature');

        abort(401, 'Unauthorized.');

        return $next($request);
    }
}
