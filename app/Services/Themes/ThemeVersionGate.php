<?php

namespace App\Services\Themes;

use App\Models\Site;
use App\Models\Theme;

class ThemeVersionGate
{
    public function shouldSkip(Site $site, Theme $theme): bool
    {
        $minimum = trim((string) ($theme->minimum_deamon_version ?? ''));
        if ($minimum === '') {
            return false;
        }

        $reported = $site->reportedDeamonVersion();
        if ($reported === null) {
            return false;
        }

        return version_compare($this->normalize($reported), $this->normalize($minimum), '<');
    }

    private function normalize(string $version): string
    {
        return ltrim($version, 'vV');
    }
}
