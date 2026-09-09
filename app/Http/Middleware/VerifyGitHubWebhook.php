<?php

namespace App\Http\Middleware;

use App\Models\GithubSetting;
use App\Support\GitHubWebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyGitHubWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = GithubSetting::resolvedWebhookSecret();
        if ($secret === null || $secret === '') {
            Log::warning('GitHub webhook rejected: signing secret is not configured');

            abort(401, 'Unauthorized.');
        }

        $header = GitHubWebhookSignature::headerFromRequest($request);
        if (! GitHubWebhookSignature::matches($secret, $request->getContent(), $header)) {
            Log::warning('GitHub webhook rejected: invalid signature');

            abort(401, 'Unauthorized.');
        }

        return $next($request);
    }
}
