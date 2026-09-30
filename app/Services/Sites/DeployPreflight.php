<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApplicationService;
use Throwable;

/**
 * Reconcile the Coolify app with Plane right before a deploy starts.
 *
 * Plane is the source of truth for hosts, env and the agent secret; Coolify drifts
 * (a host added in Plane but never bound, a catalog key missing, an app created
 * without the secret). A deploy on a drifted app builds fine and still lands on the
 * CodRon placeholder or rejects every agent call. So every deploy path runs this
 * first: inspect live, apply the fixes that are safe before a build, then deploy.
 * Coolify writes Traefik labels at deploy time, so a domain PATCH here takes effect
 * in the very deploy that follows.
 *
 * Never blocks the deploy: a Coolify hiccup or a refused PATCH is recorded on the
 * audit log and the deploy still starts (a rescue deploy must not wait on this).
 * Only fixes that change no data and start no build run here; restart, redeploy,
 * compose migration and secret rotation stay operator actions. Catalog env is not
 * repeated here: CoolifyApplicationService::deploy() already syncs it on every deploy.
 */
class DeployPreflight
{
    /**
     * Fix keys (SiteAppHealthIssue::$fix) this preflight applies, in order.
     *
     * @var list<string>
     */
    public const FIXES = ['bind_domains', 'inject_secret'];

    public function __construct(
        private readonly SiteAppHealthInspector $inspector,
        private readonly SiteAgentSecretInjector $secrets,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('ops.deploy_preflight.enabled', true);
    }

    /**
     * @return array{checked: bool, issues: list<string>, fixed: list<string>, failed: array<string, string>}
     */
    public function run(Site $site, ?User $actor = null, ?string $ip = null): array
    {
        $result = ['checked' => false, 'issues' => [], 'fixed' => [], 'failed' => []];

        if (! self::enabled() || blank($site->coolify_app_uuid)) {
            return $result;
        }

        try {
            $report = $this->inspector->inspect($site, live: true);
        } catch (Throwable $exception) {
            $result['failed']['inspect'] = $exception->getMessage();
            $this->audit($site, $actor, $ip, $result);

            return $result;
        }

        $result['checked'] = true;

        $wanted = [];
        foreach ($report->issues as $issue) {
            if (! $issue instanceof SiteAppHealthIssue) {
                continue;
            }
            $result['issues'][] = $issue->key !== null && $issue->key !== '' ? $issue->code.':'.$issue->key : $issue->code;
            if ($issue->fix !== null && in_array($issue->fix, self::FIXES, true)) {
                $wanted[$issue->fix] = true;
            }
        }

        // The inspector only sees a blank secret. A Coolify value that differs from
        // Plane's (an app re-created, an env edited by hand) builds fine and then
        // rejects every signed call; Plane's secret is the one to keep.
        if (! isset($wanted['inject_secret']) && $this->secretDrifted($site)) {
            $wanted['inject_secret'] = true;
            $result['issues'][] = 'agent_secret_mismatch';
        }

        foreach (self::FIXES as $fix) {
            if (! isset($wanted[$fix])) {
                continue;
            }

            try {
                if ($this->apply($site, $fix, $actor, $ip)) {
                    $result['fixed'][] = $fix;
                }
            } catch (Throwable $exception) {
                $result['failed'][$fix] = $exception->getMessage();
            }
        }

        if ($result['issues'] !== [] || $result['failed'] !== []) {
            $this->audit($site, $actor, $ip, $result);
        }

        return $result;
    }

    /**
     * @return bool whether a change was written to Coolify
     */
    private function apply(Site $site, string $fix, ?User $actor, ?string $ip): bool
    {
        $fresh = $site->fresh() ?? $site;

        switch ($fix) {
            case 'bind_domains':
                if (! $fresh->canBindCoolifyDomains() || $fresh->coolifyDomainBinding() === '') {
                    return false;
                }
                // Resolved late: SiteLanding depends on CoolifyDeploySettings, which calls this.
                // While DNS is pending the binding is the temporary preview host.
                app(SiteLanding::class)->syncCoolifyDomains($fresh);

                return true;
            case 'inject_secret':
                // rotate=false: writes Plane's current secret (or a first one when
                // there is none). Never rotates a live secret.
                $this->secrets->inject($fresh, $actor, $ip);

                return true;
        }

        return false;
    }

    private function secretDrifted(Site $site): bool
    {
        if (! $site->hasAgentSecret()) {
            return false;
        }

        try {
            $envs = CoolifyApplicationService::forSite($site)->listEnvs((string) $site->coolify_app_uuid);
        } catch (Throwable) {
            return false;
        }

        foreach ($envs as $env) {
            if ($env->key !== 'CONTROL_PLANE_AGENT_SECRET' || $env->isPreview) {
                continue;
            }
            $value = $env->value();

            // Coolify hides the value from a token without read:sensitive; then there is nothing to compare.
            return $value !== null && $value !== '' && ! hash_equals((string) $site->agent_secret_encrypted, $value);
        }

        return false;
    }

    /**
     * @param  array{checked: bool, issues: list<string>, fixed: list<string>, failed: array<string, string>}  $result
     */
    private function audit(Site $site, ?User $actor, ?string $ip, array $result): void
    {
        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'site.deploy_preflight',
            'after' => $result,
            'ip' => $ip,
        ]);
    }
}
