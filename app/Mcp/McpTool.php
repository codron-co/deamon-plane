<?php

namespace App\Mcp;

interface McpTool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema of the arguments.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Token ability the call needs: McpToken::ABILITY_READ, _WRITE or _DANGER.
     */
    public function ability(): string;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(McpContext $context, array $arguments): array;
}
