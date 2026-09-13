<?php

namespace App\Support\Ops;

/**
 * List-filter jumps for Ctrl/⌘+K. Empty query stays pages-only;
 * these URLs appear only when the typed needle matches a triage set
 * the lists already understand.
 */
final class PaletteFilters
{
    /**
     * @return list<array{id: string, label: string, hint: string, url: string, needles: list<string>}>
     */
    public static function catalog(): array
    {
        $hours = (int) config('ops.fleet.failed_deploy_window_hours');

        return [
            [
                'id' => 'sites-unhealthy',
                'label' => (string) __('sites.health_states.unhealthy'),
                'hint' => (string) __('ops.nav.sites'),
                'url' => route('ops.sites', ['health' => 'unhealthy']),
                'needles' => ['unhealthy', 'sağlıksız', 'sagliksiz'],
            ],
            [
                'id' => 'sites-app-issues',
                'label' => (string) __('sites.app_states.issues'),
                'hint' => (string) __('ops.nav.sites'),
                'url' => route('ops.sites', ['app' => 'issues']),
                'needles' => ['issues', 'app hatası', 'app hatasi'],
            ],
            [
                'id' => 'sites-deploy-failed',
                'label' => (string) __('sites.deploy_states.failed', ['hours' => $hours]),
                'hint' => (string) __('ops.nav.sites'),
                'url' => route('ops.sites', ['deploy' => 'failed']),
                'needles' => ['failed', 'başarısız', 'basarisiz'],
            ],
            [
                'id' => 'sites-dockerfile',
                'label' => (string) __('sites.pack_states.dockerfile'),
                'hint' => (string) __('ops.nav.sites'),
                'url' => route('ops.sites', ['pack' => 'dockerfile']),
                'needles' => ['dockerfile', 'kalanları', 'kalanlari'],
            ],
            [
                'id' => 'domains-unbound',
                'label' => (string) __('domains.filter_unbound'),
                'hint' => (string) __('ops.nav.domains'),
                'url' => route('ops.domains', ['unbound' => 1]),
                'needles' => ['unbound', 'bağlı değil', 'bagli degil'],
            ],
            [
                'id' => 'activity-failed',
                'label' => (string) __('ops.activity.outcomes.failed'),
                'hint' => (string) __('ops.nav.activity'),
                'url' => route('ops.activity', ['outcome' => 'failed']),
                'needles' => ['failed', 'başarısız', 'basarisiz'],
            ],
        ];
    }

    /**
     * @return list<array{id: string, label: string, hint: string, url: string, needles: list<string>}>
     */
    public static function matching(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return array_values(array_filter(
            self::catalog(),
            static fn (array $filter): bool => self::matches($q, $filter),
        ));
    }

    /**
     * @param  array{id: string, label: string, hint: string, url: string, needles: list<string>}  $filter
     */
    public static function matches(string $q, array $filter): bool
    {
        foreach (array_merge([$filter['label'], $filter['id']], $filter['needles']) as $haystack) {
            if (mb_stripos((string) $haystack, $q) !== false) {
                return true;
            }
        }

        return false;
    }
}
