<?php

namespace App\Services\Themes;

use App\Enums\ThemeInstallationStatus;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;

/**
 * Moves every opted-in installation of a theme to the catalog's new commit.
 * One path for a plain push and for a CI-gated theme whose `CI` run went green.
 *
 * Rules: only `auto_update` installations in Active/Error; an installation on a
 * different ref is skipped; `ops.themes.fanout_concurrency` jobs per 10 s wave;
 * at most 200 per event. The CMS-version skip lives in ThemeRolloutService.
 */
class ThemeWebhookFanout
{
    public const MAX_PER_EVENT = 200;

    public function __construct(
        private readonly ThemeRolloutService $rollout = new ThemeRolloutService,
    ) {}

    /**
     * @return array{fanout: int, skipped: int}
     */
    public function fanOut(Theme $theme, string $defaultRef): array
    {
        $fanout = 0;
        $skipped = 0;
        $concurrency = max(1, (int) config('ops.themes.fanout_concurrency', 3));
        $index = 0;

        $installations = SiteThemeInstallation::query()
            ->where('theme_id', $theme->id)
            ->where('auto_update', true)
            ->whereIn('status', [
                ThemeInstallationStatus::Active->value,
                ThemeInstallationStatus::Error->value,
            ])
            ->get();

        foreach ($installations as $installation) {
            if ($installation->ref !== '' && $installation->ref !== $defaultRef) {
                $skipped++;

                continue;
            }

            $delay = (int) floor($index / $concurrency) * 10;
            $this->rollout->updateToLatest($installation, null, null, fromWebhook: true, delaySeconds: $delay);
            $fanout++;
            $index++;

            if ($index >= self::MAX_PER_EVENT) {
                break;
            }
        }

        return ['fanout' => $fanout, 'skipped' => $skipped];
    }
}
