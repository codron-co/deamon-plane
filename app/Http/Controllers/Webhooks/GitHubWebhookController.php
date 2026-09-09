<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\GitHub\GitHubWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, GitHubWebhookHandler $handler): JsonResponse
    {
        $event = (string) $request->header('X-GitHub-Event', '');
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            $payload = [];
        }

        return response()->json($handler->handle($event, $payload));
    }
}
