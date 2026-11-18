<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpTool;
use App\Mcp\Tools\Concerns\PresentsSecurity;
use App\Models\McpToken;
use App\Services\Security\FleetSecurityResponse;
use InvalidArgumentException;

class AddFleetAllowlistTool implements McpTool
{
    use PresentsSecurity;

    public function name(): string
    {
        return 'add_fleet_allowlist';
    }

    public function description(): string
    {
        return 'Add an IP or CIDR to the fleet allowlist: no site will ever block it, and cross-site alerts skip it. Pushed to every site (test site first, background job). Idempotent. Pass confirmed: true after the user agrees. Removing an entry is a Super Admin panel action.';
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
                'label' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Whose address this is.'],
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
        self::requireWriteAndConfirmation($context, $arguments, 'Allowlisting an IP');

        try {
            $result = app(FleetSecurityResponse::class)->allow(
                $ip,
                isset($arguments['label']) ? (string) $arguments['label'] : null,
                $context->user,
                $context->ip,
            );
        } catch (InvalidArgumentException $exception) {
            throw new McpException($exception->getMessage(), 'validation_error');
        }

        return [
            'ok' => true,
            'entry' => self::allowRow($result['entry']),
            'reused' => $result['reused'],
            'job' => self::jobRow($result['job']),
            'next' => 'Poll list_jobs with job_id='.($result['job']->id ?? '').'.',
        ];
    }
}
