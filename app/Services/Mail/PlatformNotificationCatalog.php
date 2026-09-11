<?php

namespace App\Services\Mail;

/**
 * Software / ops notification keys for Deamon fleet mail (not Hostinger mailboxes).
 */
final class PlatformNotificationCatalog
{
    public const PASSWORD_RESET = 'password_reset';

    public const ADMIN_WELCOME = 'admin_welcome';

    public const WEEKLY_VISITOR_REPORT = 'weekly_visitor_report';

    public const MEMBER_NEW = 'member_new';

    public const ORDER_NEW = 'order_new';

    public const SITE_PUBLISHED = 'site_published';

    public const SITE_UNPUBLISHED = 'site_unpublished';

    public const SITE_DOWN = 'site_down';

    public const SITE_UP = 'site_up';

    public const SITE_VERSION_UPDATE = 'site_version_update';

    public const DEPLOY_FAILED = 'deploy_failed';

    /**
     * @return array<string, array{label: string, description: string, default: bool, scope: string}>
     */
    public static function definitions(): array
    {
        return [
            self::PASSWORD_RESET => [
                'label' => 'Admin password reset',
                'description' => 'CMS admin “forgot password” link.',
                'default' => true,
                'scope' => 'cms',
            ],
            self::ADMIN_WELCOME => [
                'label' => 'New admin account',
                'description' => 'Welcome mail when a panel admin is created.',
                'default' => true,
                'scope' => 'cms',
            ],
            self::WEEKLY_VISITOR_REPORT => [
                'label' => 'Weekly visitor report',
                'description' => 'Monday 08:00 Europe/Istanbul by default; overridable per site.',
                'default' => true,
                'scope' => 'cms',
            ],
            self::MEMBER_NEW => [
                'label' => 'New member',
                'description' => 'Storefront customer registration → site admin.',
                'default' => true,
                'scope' => 'cms',
            ],
            self::ORDER_NEW => [
                'label' => 'New order',
                'description' => 'New order → site admin (customer order mail stays on site mail module).',
                'default' => true,
                'scope' => 'cms',
            ],
            self::SITE_PUBLISHED => [
                'label' => 'Site published',
                'description' => 'CMS publish toggle → Yayında.',
                'default' => true,
                'scope' => 'cms',
            ],
            self::SITE_UNPUBLISHED => [
                'label' => 'Site unpublished',
                'description' => 'CMS publish toggle → Taslak.',
                'default' => true,
                'scope' => 'cms',
            ],
            self::SITE_DOWN => [
                'label' => 'Site down / unhealthy',
                'description' => 'Agent health failed.',
                'default' => true,
                'scope' => 'plane',
            ],
            self::SITE_UP => [
                'label' => 'Site recovered',
                'description' => 'Agent health recovered after a failure.',
                'default' => true,
                'scope' => 'plane',
            ],
            self::SITE_VERSION_UPDATE => [
                'label' => 'Deamon version updated',
                'description' => 'Health deamon_version changed (major / minor / patch threshold).',
                'default' => true,
                'scope' => 'plane',
            ],
            self::DEPLOY_FAILED => [
                'label' => 'Deploy failed',
                'description' => 'Coolify deployment failed.',
                'default' => true,
                'scope' => 'plane',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultNotifications(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $definition) {
            $out[$key] = ['enabled' => (bool) $definition['default']];
        }

        $out[self::WEEKLY_VISITOR_REPORT] = [
            'enabled' => true,
            'day' => 1,
            'hour' => 8,
        ];

        $out[self::SITE_VERSION_UPDATE] = [
            'enabled' => true,
            'on' => 'patch',
        ];

        return $out;
    }
}
