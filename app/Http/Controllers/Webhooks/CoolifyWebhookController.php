<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Coolify\CoolifyWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoolifyWebhookController extends Controller
{
    public function __invoke(Request $request, CoolifyWebhookHandler $handler): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            $payload = [];
        }

        return response()->json($handler->handle($payload));
    }
}
