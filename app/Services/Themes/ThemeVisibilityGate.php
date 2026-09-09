<?php

namespace App\Services\Themes;

use App\Enums\ThemeVisibility;
use App\Models\Site;
use App\Models\Theme;

class ThemeVisibilityGate
{
    public function canAssign(Theme $theme, Site $site): bool
    {
        $visibility = $theme->visibility ?? ThemeVisibility::Private;

        return match ($visibility) {
            ThemeVisibility::PublicCatalog => true,
            ThemeVisibility::Allowlist => $theme->isAllowedFor($site),
            ThemeVisibility::Private => true,
        };
    }

    public function assertAssignable(Theme $theme, Site $site): void
    {
        if ($this->canAssign($theme, $site)) {
            return;
        }

        throw new ThemeRolloutException(
            'Theme '.$theme->theme_id.' is allowlisted and this site is not on the access list.',
        );
    }
}
