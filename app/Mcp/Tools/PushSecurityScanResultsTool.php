<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpModels;
use App\Mcp\McpTool;
use App\Mcp\Tools\Concerns\PresentsSecurity;
use App\Models\AuditLog;
use App\Models\McpToken;
use App\Services\Security\SiteSecurityClient;

/**
 * Hands one scanner result (composer audit, npm audit, gitleaks, trivy, semgrep, CSP header
 * check, CDN integrity) to a site's security module over the signed agent. Plane runs no
 * scanner here and wires no CI job: the caller supplies the tool's output.
 */
class PushSecurityScanResultsTool implements McpTool
{
    use PresentsSecurity;

    public function name(): string
    {
        return 'push_security_scan_results';
    }

    public function description(): string
    {
        return 'Push one scanner result to a site (CMS 1.2.122+): `type` is composer_audit, npm_audit, gitleaks, trivy, semgrep, csp_header_check or cdn_integrity; `payload` is the tool\'s raw JSON, or pass normalized `items`. The site stores advisories (no secret values), notifies once per advisory and marks missing ones resolved when `complete` is true. Try the test site first. Needs the user\'s agreement: pass confirmed: true.';
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
                'site' => ['type' => 'string', 'description' => 'Site slug, domain or id.'],
                'type' => ['type' => 'string', 'enum' => SiteSecurityClient::SCAN_TYPES],
                'payload' => ['type' => 'object', 'description' => 'The scanner\'s raw JSON output.'],
                'items' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Normalized findings instead of payload (package, advisory_id, severity, summary, version, fixed_version, is_production, lockfile).'],
                'lockfile' => ['type' => 'string', 'description' => 'composer.lock / package-lock path the scan covered.'],
                'production' => ['type' => 'boolean', 'description' => 'The scan covered production dependencies.'],
                'complete' => ['type' => 'boolean', 'description' => 'Full scan: open findings missing from it become resolved (site default true).'],
                'scanned_at' => ['type' => 'string', 'description' => 'UTC ISO 8601.'],
                'confirmed' => ['type' => 'boolean', 'description' => 'Set true once the user has agreed.'],
            ],
            'required' => ['site', 'type', 'confirmed'],
            'additionalProperties' => false,
        ];
    }

    public function handle(McpContext $context, array $arguments): array
    {
        self::requireWriteAndConfirmation($context, $arguments, 'Pushing scan results');

        $site = McpModels::site($arguments['site'] ?? '');
        $type = (string) ($arguments['type'] ?? '');
        if (! in_array($type, SiteSecurityClient::SCAN_TYPES, true)) {
            throw new McpException('Unknown `type`. Use one of: '.implode(', ', SiteSecurityClient::SCAN_TYPES).'.', 'validation_error');
        }

        $payload = isset($arguments['items']) ? ['items' => (array) $arguments['items']] : (array) ($arguments['payload'] ?? []);
        // An empty `items` list is a valid "nothing found" result for a full scan.
        if (! isset($arguments['items']) && $payload === []) {
            throw new McpException('Pass `payload` (raw scanner JSON) or `items`.', 'validation_error');
        }

        $result = app(SiteSecurityClient::class)->pushScanResults(
            $site,
            $type,
            $payload,
            array_intersect_key($arguments, array_flip(['lockfile', 'production', 'complete', 'scanned_at'])),
        );

        AuditLog::query()->create([
            'actor_user_id' => $context->user->id,
            'action' => 'security.scan_push',
            'subject_type' => $site->getMorphClass(),
            'subject_id' => (string) $site->getKey(),
            'after' => ['type' => $type, 'ok' => $result->isOk(), 'state' => $result->state, 'http_status' => $result->httpStatus],
            'ip' => $context->ip,
        ]);

        if (! $result->isOk()) {
            throw new McpException($result->message !== '' ? $result->message : 'The site did not take the scan result.', $result->isUnsupported() ? 'unsupported' : 'upstream_error', ['site' => $site->slug, 'state' => $result->state]);
        }

        return [
            'ok' => true,
            'site' => $site->slug,
            'type' => $type,
            'items' => $result->payload['items'] ?? null,
            'new' => $result->payload['new'] ?? null,
            'updated' => $result->payload['updated'] ?? null,
            'resolved' => $result->payload['resolved'] ?? null,
            'events' => $result->payload['events'] ?? null,
        ];
    }
}
