<?php

namespace App\Services\Themes;

use App\Models\Site;
use App\Models\Theme;
use App\Services\Sites\SiteLiveProbe;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GETs the storefront after a theme install / update / sync. A theme can install
 * cleanly and still 500 on a page (moonagro /timeline: a core partial the CMS
 * volume did not have), so "the agent said ok" is not enough.
 *
 * Only a 5xx other than 503 fails: 503 is the CMS maintenance page (unpublished
 * site) and a connection error says nothing about the theme. A failing path is
 * asked twice so one slow worker does not roll a good theme back.
 */
class ThemeSmokeCheck
{
    public function __construct(
        private readonly SiteLiveProbe $probe = new SiteLiveProbe,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ops.themes.smoke.enabled', true);
    }

    public function run(Site $site, Theme $theme): ThemeSmokeResult
    {
        $base = $this->probe->homepageUrl($site);
        if (! $this->enabled() || $base === null) {
            return ThemeSmokeResult::skipped();
        }

        $failures = [];
        $checked = [];

        foreach ($this->paths($theme) as $path) {
            $url = rtrim($base, '/').$path;
            $status = $this->status($url);
            if ($this->isFailure($status)) {
                $status = $this->status($url);
            }

            $checked[$path] = $status;
            if ($this->isFailure($status)) {
                $failures[$path] = $status;
            }
        }

        return new ThemeSmokeResult(skipped: false, checked: $checked, failures: $failures);
    }

    /**
     * @return list<string>
     */
    public function paths(Theme $theme): array
    {
        $declared = is_array($theme->smoke_paths) ? $theme->smoke_paths : [];

        return array_values(array_unique(['/', ...array_filter($declared, 'is_string')]));
    }

    private function status(string $url): int
    {
        try {
            $response = Http::timeout((int) config('ops.themes.smoke.timeout', 15))
                ->connectTimeout(5)
                ->withUserAgent('Deamon-Plane-ThemeSmoke/1')
                ->withOptions(['allow_redirects' => true])
                ->get($url);
        } catch (Throwable) {
            return 0;
        }

        return $response instanceof Response ? $response->status() : 0;
    }

    private function isFailure(int $status): bool
    {
        return $status >= 500 && $status !== 503;
    }
}
