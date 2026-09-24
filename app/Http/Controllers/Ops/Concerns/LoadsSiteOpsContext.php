<?php

namespace App\Http\Controllers\Ops\Concerns;

use App\Models\Site;
use App\Models\Theme;
use App\Services\Themes\ThemeVisibilityGate;
use Illuminate\Support\Collection;

trait LoadsSiteOpsContext
{
    /**
     * @return list<string>
     */
    private function channelSwitchTargets(Site $site): array
    {
        $current = $site->channel->value;

        return array_values(array_filter(
            config('ops.channels', []),
            static fn (string $channel): bool => $channel !== $current,
        ));
    }

    /**
     * @return Collection<int, Theme>
     */
    private function assignableThemes(Site $site): Collection
    {
        $gate = new ThemeVisibilityGate;

        return Theme::query()
            ->orderBy('theme_id')
            ->get()
            ->filter(static fn (Theme $theme): bool => $gate->canAssign($theme, $site))
            ->sortBy(static fn (Theme $theme): string => mb_strtolower($theme->displayName()), SORT_NATURAL)
            ->values();
    }
}
