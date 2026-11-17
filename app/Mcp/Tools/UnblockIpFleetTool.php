<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpTool;
use App\Mcp\Tools\Concerns\PresentsSecurity;
use App\Models\McpToken;
use App\Services\Security\FleetSecurityResponse;
use InvalidArgumentException;

class UnblockIpFleetTool implements McpTool
{
    use PresentsSecurity;

    public function name(): string
    {
        return 'unblock_ip_fleet';
    }

    public function description(): string
    {
        return 'Release an IP that block_ip_fleet blocked, on every site (test site first, background job). Idempotent. Pass confirmed: true after the user agrees.';
    }

    public function ability(): string
    {
        return McpToken::ABILITY_WRITE;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ip' => ['type' => 'string', 'description' => 'The address or CIDR exactly as it was blocked.'],
                'confirmed' => ['type' => 'boolean', 'description' => 'Set true once the user has agreed.'],
            ],
            'required' => ['ip', 'confirmed'],
            'additionalProperties' => false,
        ];
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $ip = trim((string) ($arguments['ip'] ?? ''));
        if ($ip === '') {
            throw new McpException('`ip` is required.', 'validation_error');
        }
        self::requireWriteAndConfirmation($context, $arguments, 'Releasing an IP');

        try {
            $result = app(FleetSecurityResponse::class)->unblock($ip, $context->user, $context->ip);
        } catch (InvalidArgumentException $exception) {
            throw new McpException($exception->getMessage(), 'validation_error');
        }

        if ($result['block'] === null) {
            return ['ok' => true, 'released' => false, 'message' => "{$ip} has no active fleet block."];
        }

        return [
            'ok' => true,
            'released' => true,
            'block' => self::blockRow($result['block']),
            'job' => self::jobRow($result['job']),
            'next' => 'Poll list_jobs with job_id='.($result['job']->id ?? '').'.',
        ];
    }
}
