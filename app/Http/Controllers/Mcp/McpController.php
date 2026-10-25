<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\McpContext;
use App\Mcp\PlaneMcpServer;
use App\Models\McpToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * MCP Streamable HTTP endpoint. POST carries JSON-RPC (one message or a batch)
 * and gets JSON back; Plane never streams, so GET answers 405 as the spec allows.
 */
class McpController extends Controller
{
    public function __invoke(Request $request, PlaneMcpServer $server): JsonResponse|Response
    {
        if ($request->isMethod('GET')) {
            return response('', 405, ['Allow' => 'POST, DELETE']);
        }

        if ($request->isMethod('DELETE')) {
            return response()->noContent();
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (! is_array($payload) || $payload === []) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }

        /** @var McpToken $token */
        $token = $request->attributes->get('mcp_token');
        $context = new McpContext($token->owner(), $token, $request->ip());
        $session = $request->header('Mcp-Session-Id') ?: (string) Str::ulid();

        if (array_is_list($payload)) {
            $responses = array_values(array_filter(array_map(
                fn ($message) => is_array($message) ? $server->handle($message, $context) : null,
                $payload,
            )));

            return $responses === []
                ? response()->noContent(202)
                : response()->json($responses)->header('Mcp-Session-Id', $session);
        }

        $response = $server->handle($payload, $context);

        return $response === null
            ? response()->noContent(202)
            : response()->json($response)->header('Mcp-Session-Id', $session);
    }
}
