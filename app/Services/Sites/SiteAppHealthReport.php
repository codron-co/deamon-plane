<?php

namespace App\Services\Sites;

use App\Models\Site;
use Illuminate\Support\Carbon;

final class SiteAppHealthReport
{
    /**
     * @param  list<SiteAppHealthIssue>  $issues
     */
    public function __construct(
        public readonly bool $checked,
        public readonly bool $ok,
        public readonly ?string $buildPack,
        public readonly ?string $composeLocation,
        public readonly array $issues,
        public readonly ?Carbon $checkedAt,
    ) {}

    public static function unchecked(): self
    {
        return new self(false, false, null, null, [], null);
    }

    /**
     * Cached Coolify inspect when present; otherwise local signals only.
     */
    public static function forDisplay(Site $site): self
    {
        $stored = self::fromStored($site);
        if ($stored->checked) {
            return $stored;
        }

        return app(SiteAppHealthInspector::class)->localReport($site);
    }

    public static function fromStored(Site $site): self
    {
        $payload = is_array($site->last_app_health_payload) ? $site->last_app_health_payload : [];
        if ($payload === [] || ! array_key_exists('issues', $payload)) {
            return self::unchecked();
        }

        $issues = [];
        foreach ($payload['issues'] ?? [] as $row) {
            if (is_array($row)) {
                $issues[] = SiteAppHealthIssue::fromArray($row);
            }
        }

        return new self(
            checked: true,
            ok: (bool) ($payload['ok'] ?? $issues === []),
            buildPack: is_string($payload['build_pack'] ?? null) ? $payload['build_pack'] : null,
            composeLocation: is_string($payload['compose_location'] ?? null) ? $payload['compose_location'] : null,
            issues: $issues,
            checkedAt: $site->last_app_health_at,
        );
    }

    /**
     * @return array{ok: bool, build_pack: string|null, compose_location: string|null, issues: list<array{code: string, fix: string|null, key: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'build_pack' => $this->buildPack,
            'compose_location' => $this->composeLocation,
            'issues' => array_map(
                static fn (SiteAppHealthIssue $issue): array => $issue->toArray(),
                $this->issues,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toView(?Site $site = null): array
    {
        $count = $this->count();

        return [
            'site_id' => $site?->id,
            'checked' => $this->checked,
            'ok' => $this->ok,
            'tone' => $this->tone(),
            'label' => $this->label(),
            'copy_text' => $this->copyText(),
            'count' => $count,
            'build_pack' => $this->buildPack,
            'compose_location' => $this->composeLocation,
            'checked_at' => $this->checkedAt?->toIso8601String(),
            'issues' => array_map(static fn (SiteAppHealthIssue $issue): array => [
                'code' => $issue->code,
                'fix' => $issue->fix,
                'key' => $issue->key,
                'message' => $issue->message(),
                'fix_label' => $issue->fixLabel(),
            ], $this->issues),
        ];
    }

    public function count(): int
    {
        return count($this->issues);
    }

    public function tone(): string
    {
        if (! $this->checked && $this->issues === []) {
            return 'unknown';
        }

        return $this->ok ? 'ok' : 'error';
    }

    public function label(): string
    {
        if (! $this->checked && $this->issues === []) {
            return __('sites.app_health.unchecked');
        }

        if ($this->ok) {
            return __('sites.app_health.healthy');
        }

        return trans_choice('sites.app_health.issues_count', $this->count(), [
            'count' => $this->count(),
        ]);
    }

    public function copyText(): string
    {
        if ($this->ok) {
            return __('sites.app_health.healthy_detail');
        }

        if ($this->issues === []) {
            return __('sites.app_health.unchecked_detail');
        }

        $lines = array_map(
            static fn (SiteAppHealthIssue $issue): string => $issue->message(),
            $this->issues,
        );

        return implode("\n", $lines);
    }
}
