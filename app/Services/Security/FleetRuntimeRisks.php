<?php

namespace App\Services\Security;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteSecurityCursor;

/**
 * What the CMS runtime sensors (1.2.122+) report, per site and for the fleet:
 * insecure settings, php.ini drift, PHP end of life, telemetry gaps, open
 * production advisories, file integrity. Read from the stored summary block and
 * the health `security` block; a CMS that sends neither shows up as "unknown",
 * never as healthy. Also picks the five riskiest alerts across the fleet.
 */
class FleetRuntimeRisks
{
    public const SEV_WARNING = 1;

    public const SEV_CRITICAL = 2;

    /** Settings whose being on is critical by itself (CMS ranks them `critical`). */
    private const CRITICAL_SETTINGS = ['app_debug', 'allow_php_edit'];

    private const CRITICAL_INI = ['display_errors', 'allow_url_include', 'session.upload_progress.enabled'];

    /** No ingest heartbeat for this long = the telemetry is dark (CMS: >5 min is critical). */
    private const INGEST_STALE_SECONDS = 300;

    private const OUTBOX_LAG_SECONDS = 600;

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $sites = Site::query()
            ->where('status', SiteStatus::Active->value)
            ->whereNotNull('agent_secret_encrypted')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'last_health_payload']);
        $cursors = SiteSecurityCursor::query()->whereIn('site_id', $sites->pluck('id'))->get()->keyBy('site_id');

        $rows = $sites->map(fn (Site $site): array => $this->row($site, $cursors->get($site->id)))->values();

        $risks = [];
        foreach ($rows as $row) {
            array_push($risks, ...$row['risks']);
        }
        usort($risks, fn (array $a, array $b): int => [$b['severity'], $b['weight']] <=> [$a['severity'], $a['weight']]);

        return [
            'sites_reporting' => $rows->where('known', true)->count(),
            'insecure_settings' => (int) $rows->sum('insecure_settings'),
            'sites_insecure' => $rows->where('insecure_settings', '>', 0)->count(),
            'sites_ini_drift' => $rows->filter(fn (array $row): bool => $row['ini_drift'] !== [])->count(),
            'sites_php_eol' => $rows->filter(fn (array $row): bool => in_array($row['php_eol']['status'] ?? null, ['warning', 'critical'], true))->count(),
            'sites_telemetry_gap' => $rows->filter(fn (array $row): bool => $row['telemetry_gap'])->count(),
            'critical_production' => (int) $rows->sum(fn (array $row): int => (int) ($row['scans']['critical_production'] ?? 0)),
            'high_production' => (int) $rows->sum(fn (array $row): int => (int) ($row['scans']['high_production'] ?? 0)),
            'red' => collect($risks)->contains(fn (array $risk): bool => $risk['severity'] >= self::SEV_CRITICAL),
            'top_risks' => array_slice($risks, 0, 5),
            'total_risks' => count($risks),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forSite(Site $site): array
    {
        return $this->row($site, SiteSecurityCursor::query()->where('site_id', $site->id)->first());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Site $site, ?SiteSecurityCursor $cursor): array
    {
        $summary = is_array($cursor?->summary) ? $cursor->summary : [];
        $system = is_array($summary['system'] ?? null) ? $summary['system'] : null;
        $scans = is_array($summary['scans'] ?? null) ? $summary['scans'] : null;
        $health = is_array($site->last_health_payload['security'] ?? null) ? $site->last_health_payload['security'] : [];
        $telemetry = is_array($health['telemetry'] ?? null) ? $health['telemetry'] : null;

        $insecure = $system['insecure_settings'] ?? $health['insecure_settings'] ?? null;
        $row = [
            'site' => ['id' => $site->id, 'slug' => $site->slug, 'name' => $site->name],
            'known' => $system !== null || $scans !== null || $telemetry !== null || $insecure !== null,
            'insecure_settings' => (int) ($insecure ?? 0),
            'insecure_keys' => $system['insecure_keys'] ?? [],
            'exempt' => (bool) ($system['exempt'] ?? false),
            'ini_drift' => $system['ini_drift'] ?? [],
            'php_eol' => $system['php_eol'] ?? null,
            'integrity' => $system['integrity'] ?? null,
            'telemetry' => $telemetry,
            'telemetry_gaps' => $system['telemetry_gaps'] ?? [],
            'scans' => $scans,
        ];
        $row['telemetry_gap'] = $this->telemetryGap($row);
        $row['risks'] = $this->risks($row);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function telemetryGap(array $row): bool
    {
        $t = $row['telemetry'];

        return $row['telemetry_gaps'] !== []
            || (is_array($t) && (
                ($t['ingest_age_seconds'] !== null && $t['ingest_age_seconds'] > self::INGEST_STALE_SECONDS)
                || $t['dropped_24h'] > 0
                || ($t['outbox_lag_seconds'] !== null && $t['outbox_lag_seconds'] > self::OUTBOX_LAG_SECONDS)
            ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{site_id: string, site: string, slug: string, kind: string, severity: int, weight: int, value: string}>
     */
    private function risks(array $row): array
    {
        $out = [];
        $add = function (string $kind, int $severity, int $weight, string $value = '') use (&$out, $row): void {
            $out[] = [
                'site_id' => $row['site']['id'], 'site' => $row['site']['name'], 'slug' => $row['site']['slug'],
                'kind' => $kind, 'severity' => $severity, 'weight' => $weight, 'value' => $value,
            ];
        };

        if ($row['insecure_settings'] > 0) {
            $critical = array_intersect($row['insecure_keys'], self::CRITICAL_SETTINGS) !== [];
            $add('insecure_settings', $critical ? self::SEV_CRITICAL : self::SEV_WARNING, $row['insecure_settings'], (string) $row['insecure_settings']);
        }

        if (($row['integrity']['status'] ?? null) === 'critical') {
            $add('integrity', self::SEV_CRITICAL, (int) ($row['integrity']['open'] ?? 1), (string) ($row['integrity']['open'] ?? 1));
        }

        $eol = $row['php_eol']['status'] ?? null;
        if (in_array($eol, ['warning', 'critical'], true)) {
            $add('php_eol', $eol === 'critical' ? self::SEV_CRITICAL : self::SEV_WARNING, 1, (string) ($row['php_eol']['version'] ?? ''));
        }

        if ($row['ini_drift'] !== []) {
            $keys = array_column($row['ini_drift'], 'key');
            $add('ini_drift', array_intersect($keys, self::CRITICAL_INI) !== [] ? self::SEV_CRITICAL : self::SEV_WARNING, count($keys), implode(', ', array_slice($keys, 0, 3)));
        }

        $scans = $row['scans'];
        if (($scans['critical_production'] ?? 0) > 0) {
            $add('advisory_critical', self::SEV_CRITICAL, (int) $scans['critical_production'], (string) $scans['critical_production']);
        }
        if (($scans['high_production'] ?? 0) > 0) {
            $add('advisory_high', self::SEV_WARNING, (int) $scans['high_production'], (string) $scans['high_production']);
        }

        $t = $row['telemetry'];
        if (is_array($t) && $t['ingest_age_seconds'] !== null && $t['ingest_age_seconds'] > self::INGEST_STALE_SECONDS) {
            $add('telemetry_ingest', self::SEV_CRITICAL, (int) $t['ingest_age_seconds'], (string) intdiv((int) $t['ingest_age_seconds'], 60));
        }
        if (is_array($t) && $t['dropped_24h'] > 0) {
            $add('telemetry_dropped', self::SEV_WARNING, (int) $t['dropped_24h'], (string) $t['dropped_24h']);
        }
        if (is_array($t) && $t['outbox_lag_seconds'] !== null && $t['outbox_lag_seconds'] > self::OUTBOX_LAG_SECONDS) {
            $add('telemetry_outbox', self::SEV_WARNING, (int) $t['outbox_lag_seconds'], (string) intdiv((int) $t['outbox_lag_seconds'], 60));
        }
        if ($row['telemetry_gaps'] !== [] && ! collect($out)->contains(fn ($r) => str_starts_with($r['kind'], 'telemetry'))) {
            $add('telemetry_gap', self::SEV_WARNING, count($row['telemetry_gaps']), implode(', ', array_slice($row['telemetry_gaps'], 0, 3)));
        }

        return $out;
    }
}
