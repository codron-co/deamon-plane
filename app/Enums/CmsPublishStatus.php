<?php

namespace App\Enums;

/**
 * The CMS publish state — `sites.status` in `codron-co/deamon`, reported by the
 * signed agent as `site_status`. `draft` means visitors get the maintenance page;
 * `published` means they get the storefront.
 *
 * This is NOT App\Enums\SiteStatus, which is Plane's Coolify lifecycle.
 */
enum CmsPublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status) => $status->value, self::cases());
    }

    public function label(): string
    {
        return __('sites.publish.states.'.$this->value);
    }

    /**
     * Maps onto the shared `status-{tone}` chip classes.
     */
    public function tone(): string
    {
        return $this === self::Published ? 'ok' : 'needs_secret';
    }

    public function opposite(): self
    {
        return $this === self::Published ? self::Draft : self::Published;
    }
}
