<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Models\FleetAllowlistEntry;
use App\Models\FleetIpBlock;
use App\Models\OpsBackgroundJob;
use App\Models\SiteSecurityEvent;

/**
 * Shared shapes and guards of the fleet security MCP tools. Events carry no request
 * body or secret, so presenting a row is a plain field copy.
 */
trait PresentsSecurity
{
    /**
     * @return array<string, mixed>
     */
    protected static function eventRow(SiteSecurityEvent $event): array
    {
        return [
            'id' => $event->id,
            'site' => $event->site?->slug,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'severity' => $event->severityKey(),
            'type' => $event->type,
            'rule' => $event->rule,
            'ip' => $event->ip,
            'country' => $event->country,
            'method' => $event->method,
            'path' => $event->path,
            'status' => $event->status,
            'user_agent' => $event->user_agent,
            'count' => $event->count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function blockRow(FleetIpBlock $block): array
    {
        return [
            'ip' => $block->ip,
            'reason' => $block->reason,
            'status' => $block->status,
            'expires_at' => $block->expires_at?->toIso8601String(),
            'created_at' => $block->created_at?->toIso8601String(),
            'sites' => collect((array) $block->applied)->map(fn ($row): string => (string) ($row['state'] ?? '?'))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function allowRow(FleetAllowlistEntry $entry): array
    {
        return [
            'ip' => $entry->ip,
            'label' => $entry->label,
            'source' => $entry->source,
            'status' => $entry->status,
            'created_at' => $entry->created_at?->toIso8601String(),
            'sites' => collect((array) $entry->applied)->map(fn ($row): string => (string) ($row['state'] ?? '?'))->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function jobRow(?OpsBackgroundJob $job): ?array
    {
        return $job === null ? null : ['id' => $job->id, 'status' => $job->status];
    }

    /**
     * A change that touches every site needs a role that may write and the user's agreement.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected static function requireWriteAndConfirmation(McpContext $context, array $arguments, string $what): void
    {
        if (! $context->user->canWriteOps()) {
            throw new McpException('Your Plane role cannot change fleet security.', 'forbidden');
        }

        if (($arguments['confirmed'] ?? false) !== true) {
            throw new McpException("{$what} on every site needs the user's agreement. Ask them, then call again with `confirmed: true`.", 'confirmation_required');
        }
    }
}
