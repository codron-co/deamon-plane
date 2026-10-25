<?php

namespace App\Mcp;

use RuntimeException;

/**
 * A tool call the agent can fix (bad argument, unknown site, missing ability).
 * Reported as an MCP tool error, never as a JSON-RPC transport error.
 */
class McpException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'invalid_request',
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'ok' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->details);
    }
}
