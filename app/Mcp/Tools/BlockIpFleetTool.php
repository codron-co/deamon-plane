<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpTool;
use App\Mcp\Tools\Concerns\PresentsSecurity;
use App\Models\McpToken;
use App\Services\Security\FleetSecurityResponse;
use InvalidArgumentException;

/**
 * Block an IP on every site. Same service as the panel: the test site is tried
 * first and the rest only follow if it took the block; one background job, one
 * site at a time. Needs the user's agreement (`confirmed`).
 */
class BlockIpFleetTool implements McpTool
{
    use PresentsSecurity;

    public function name(): string
    {
        return 'block_ip_fleet';
    }

    public function description(): string
    {
        return 'Block an IP (or CIDR no wider than /16) on every site: they answer it 403. Plane applies it to the test site first, then the others one by one in a background job (follow with list_jobs). Private/loopback addresses and anything overlapping the fleet allowlist are refused. Idempotent per address. Ask the user first, then pass confirmed: true. Undo with unblock_ip_fleet.';
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
                'ip' => ['type' => 'string', 'description' => 'IPv4/IPv6 address or CIDR.'],
                'reason' => ['type' => 'string', 'maxLength' => 255],
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8760, 'description' => 'Block for this many hours; omit to keep it until released.'],
                'confirmed' => ['type' => 'boolean', 'description' => 'Set true once the user has agreed to this block.'],
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
        self::requireWriteAndConfirmation($context, $arguments, 'Blocking an IP');

        $hours = isset($arguments['hours']) ? max(1, min(8760, (int) $arguments['hours'])) : null;

        try {
            $result = app(FleetSecurityResponse::class)->block(
                $ip,
                isset($arguments['reason']) ? (string) $arguments['reason'] : null,
                $hours !== null ? now()->addHours($hours) : null,
                $context->user,
                $context->ip,
            );
        } catch (InvalidArgumentException $exception) {
            throw new McpException($exception->getMessage(), 'validation_error');
        }

        return [
            'ok' => true,
            'block' => self::blockRow($result['block']),
            'reused' => $result['reused'],
            'job' => self::jobRow($result['job']),
            'next' => 'Poll list_jobs with job_id='.($result['job']->id ?? '').'. The test site is tried first; a failure there stops the rest.',
        ];
    }
}
