<?php

namespace App\Mcp;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * JSON-RPC 2.0 for the MCP Streamable HTTP transport (JSON responses, no SSE).
 */
class PlaneMcpServer
{
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly McpToolRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null for notifications
     */
    public function handle(array $message, McpContext $context): ?array
    {
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error($message['id'] ?? null, -32600, 'Invalid Request');
        }

        $id = $message['id'] ?? null;
        $method = $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (! array_key_exists('id', $message)) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params, $context),
                'ping' => new \stdClass,
                'tools/list' => ['tools' => $this->registry->catalog($context->token)],
                'tools/call' => $this->callTool($params, $context),
                'prompts/list' => ['prompts' => McpGuide::prompts()],
                'prompts/get' => McpGuide::prompt((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []),
                'resources/list' => ['resources' => []],
                default => null,
            };
        } catch (McpException $exception) {
            return $this->error($id, -32602, $exception->getMessage(), $exception->toArray());
        }

        if ($result === null) {
            return $this->error($id, -32601, 'Method not found');
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params, McpContext $context): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : '2025-06-18',
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'prompts' => ['listChanged' => false],
                'resources' => new \stdClass,
            ],
            'serverInfo' => [
                'name' => 'deamon-plane',
                'title' => 'Deamon Plane',
                'version' => '1.0.0',
            ],
            'instructions' => McpGuide::instructions($context),
        ];
    }

    /**
     * Tool failures come back as a result with isError, so the agent can read and fix them.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params, McpContext $context): array
    {
        $name = trim((string) ($params['name'] ?? ''));
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tool = $this->registry->find($name);
        if (! $tool instanceof McpTool) {
            return $this->toolResult(['ok' => false, 'code' => 'unknown_tool', 'message' => "Unknown tool `{$name}`."], true);
        }

        if (! $context->token->can($tool->ability())) {
            return $this->toolResult([
                'ok' => false,
                'code' => 'token_ability',
                'message' => "This token lacks the `{$tool->ability()}` ability for {$name}. Ask the user for a token that has it.",
            ], true);
        }

        Log::info('mcp.tool', ['tool' => $name, 'user_id' => $context->user->id, 'token_id' => $context->token->id, 'site' => $arguments['site'] ?? null]);

        try {
            return $this->toolResult($tool->handle($context, $arguments), false);
        } catch (McpException $exception) {
            return $this->toolResult($exception->toArray(), true);
        } catch (ValidationException $exception) {
            return $this->toolResult([
                'ok' => false,
                'code' => 'validation_error',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], true);
        } catch (Throwable $exception) {
            report($exception);

            return $this->toolResult([
                'ok' => false,
                'code' => 'internal_error',
                'message' => 'Plane hit an unexpected error ('.class_basename($exception).'). It is logged; check list_site_activity or retry.',
            ], true);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toolResult(array $data, bool $isError): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            'structuredContent' => $data,
            'isError' => $isError,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
