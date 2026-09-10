<?php

namespace App\Support;

use App\Enums\CoolifyEnvKind;
use App\Enums\CoolifyEnvPack;

/**
 * Factory defaults for Coolify application env catalogs.
 * Operators may edit rows after migrate; this class is the first-seed source.
 */
final class CoolifyEnvDefaultCatalog
{
    /**
     * @return list<array{pack: string, key: string, kind: string, value: ?string, is_secret: bool, sort: int, notes: ?string}>
     */
    public static function rows(): array
    {
        return array_merge(
            self::pack(CoolifyEnvPack::DockerCompose, self::compose()),
            self::pack(CoolifyEnvPack::Dockerfile, self::dockerfile()),
        );
    }

    /**
     * @param  list<array{0: string, 1: CoolifyEnvKind, 2: ?string, 3: bool, 4: ?string}>  $rows
     * @return list<array{pack: string, key: string, kind: string, value: ?string, is_secret: bool, sort: int, notes: ?string}>
     */
    private static function pack(CoolifyEnvPack $pack, array $rows): array
    {
        $out = [];
        $sort = 10;

        foreach ($rows as $row) {
            $out[] = [
                'pack' => $pack->value,
                'key' => $row[0],
                'kind' => $row[1]->value,
                'value' => $row[2],
                'is_secret' => $row[3],
                'sort' => $sort,
                'notes' => $row[4],
            ];
            $sort += 10;
        }

        return $out;
    }

    /**
     * CMS compose interpolates these from Coolify. Empty MYSQL_ROOT_PASSWORD / DB_PASSWORD
     * makes official mysql:8.0 exit on first volume init.
     *
     * @return list<array{0: string, 1: CoolifyEnvKind, 2: ?string, 3: bool, 4: ?string}>
     */
    private static function compose(): array
    {
        return array_merge(self::siteKeys(), [
            ['DB_PASSWORD', CoolifyEnvKind::Generated, '{{generated}}', true, 'compose_mysql'],
            ['MYSQL_ROOT_PASSWORD', CoolifyEnvKind::Generated, '{{generated}}', true, 'compose_mysql_root'],
            ['DEAMON_DEFAULT_ADMIN_PASSWORD', CoolifyEnvKind::Generated, '{{generated}}', true, 'admin_seed'],
            ['APP_NAME', CoolifyEnvKind::Static, 'Deamon', false, 'app_name'],
            ['APP_DEBUG', CoolifyEnvKind::Static, 'false', false, 'app_debug'],
            ['APP_TIMEZONE', CoolifyEnvKind::Static, 'Europe/Istanbul', false, 'timezone'],
            ['LOG_CHANNEL', CoolifyEnvKind::Static, 'stderr', false, 'log_channel'],
            ['DB_CONNECTION', CoolifyEnvKind::Static, 'mysql', false, 'compose_db'],
            ['DB_HOST', CoolifyEnvKind::Static, 'mysql', false, 'compose_db_host'],
            ['DB_PORT', CoolifyEnvKind::Static, '3306', false, 'compose_db'],
            ['DB_DATABASE', CoolifyEnvKind::Static, 'deamon', false, 'compose_db'],
            ['DB_USERNAME', CoolifyEnvKind::Static, 'deamon', false, 'compose_db'],
            ['REDIS_CLIENT', CoolifyEnvKind::Static, 'phpredis', false, 'compose_redis'],
            ['REDIS_HOST', CoolifyEnvKind::Static, 'redis', false, 'compose_redis'],
            ['REDIS_PORT', CoolifyEnvKind::Static, '6379', false, 'compose_redis'],
            ['QUEUE_CONNECTION', CoolifyEnvKind::Static, 'redis', false, 'compose_redis'],
            ['CACHE_STORE', CoolifyEnvKind::Static, 'redis', false, 'compose_redis'],
            ['SESSION_DRIVER', CoolifyEnvKind::Static, 'redis', false, 'compose_redis'],
            ['SESSION_SECURE_COOKIE', CoolifyEnvKind::Static, 'true', false, 'session_secure'],
            ['FILESYSTEM_DISK', CoolifyEnvKind::Static, 'public', false, 'filesystem'],
            ['MAIL_MAILER', CoolifyEnvKind::Static, 'log', false, 'mail_log'],
            ['TRUSTED_PROXIES', CoolifyEnvKind::Static, '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16', false, 'trusted_proxies'],
            ['WAIT_FOR_DB', CoolifyEnvKind::Static, 'true', false, 'wait'],
            ['WAIT_FOR_REDIS', CoolifyEnvKind::Static, 'true', false, 'wait'],
            ['RUN_DEPLOY_TASKS', CoolifyEnvKind::Static, 'true', false, 'deploy_tasks'],
        ], self::platformMail(), self::coolifySkip());
    }

    /**
     * Dockerfile apps keep an external DB. Do not force compose hostnames or MYSQL_ROOT_PASSWORD.
     *
     * @return list<array{0: string, 1: CoolifyEnvKind, 2: ?string, 3: bool, 4: ?string}>
     */
    private static function dockerfile(): array
    {
        return array_merge(self::siteKeys(), [
            ['DB_PASSWORD', CoolifyEnvKind::Generated, '{{generated}}', true, 'external_db_password'],
            ['DEAMON_DEFAULT_ADMIN_PASSWORD', CoolifyEnvKind::Generated, '{{generated}}', true, 'admin_seed'],
            ['APP_NAME', CoolifyEnvKind::Static, 'Deamon', false, 'app_name'],
            ['APP_DEBUG', CoolifyEnvKind::Static, 'false', false, 'app_debug'],
            ['APP_TIMEZONE', CoolifyEnvKind::Static, 'Europe/Istanbul', false, 'timezone'],
            ['DB_CONNECTION', CoolifyEnvKind::Static, 'mysql', false, 'external_db'],
            ['DB_HOST', CoolifyEnvKind::Required, null, false, 'external_db_host'],
            ['DB_PORT', CoolifyEnvKind::Static, '3306', false, 'external_db'],
            ['DB_DATABASE', CoolifyEnvKind::Required, null, false, 'external_db'],
            ['DB_USERNAME', CoolifyEnvKind::Required, null, false, 'external_db'],
            ['REDIS_HOST', CoolifyEnvKind::Required, null, false, 'external_redis'],
            ['REDIS_PORT', CoolifyEnvKind::Static, '6379', false, 'external_redis'],
            ['SESSION_SECURE_COOKIE', CoolifyEnvKind::Static, 'true', false, 'session_secure'],
            ['MAIL_MAILER', CoolifyEnvKind::Static, 'log', false, 'mail_log'],
            ['TRUSTED_PROXIES', CoolifyEnvKind::Static, '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16', false, 'trusted_proxies'],
        ], self::platformMail(), self::coolifySkip());
    }

    /**
     * @return list<array{0: string, 1: CoolifyEnvKind, 2: ?string, 3: bool, 4: ?string}>
     */
    private static function siteKeys(): array
    {
        return [
            ['APP_KEY', CoolifyEnvKind::Site, '{{site.app_key}}', true, 'app_key'],
            ['DEAMON_SITE_NAME', CoolifyEnvKind::Site, '{{site.name}}', false, 'site_name'],
            ['DEAMON_CHANNEL', CoolifyEnvKind::Site, '{{site.channel}}', false, 'site_channel'],
            ['APP_ENV', CoolifyEnvKind::Site, '{{site.app_env}}', false, 'app_env'],
            ['CONTROL_PLANE_AGENT_SECRET', CoolifyEnvKind::Site, '{{site.agent_secret}}', true, 'agent_secret'],
        ];
    }

    /**
     * @return list<array{0: string, 1: CoolifyEnvKind, 2: ?string, 3: bool, 4: ?string}>
     */
    private static function platformMail(): array
    {
        return [
            ['DEAMON_PLATFORM_MAIL_HOST', CoolifyEnvKind::Static, 'smtp.hostinger.com', false, 'platform_mail'],
            ['DEAMON_PLATFORM_MAIL_PORT', CoolifyEnvKind::Static, '465', false, 'platform_mail'],
            ['DEAMON_PLATFORM_MAIL_SCHEME', CoolifyEnvKind::Static, 'smtps', false, 'platform_mail'],
            ['DEAMON_PLATFORM_MAIL_ENCRYPTION', CoolifyEnvKind::Static, 'ssl', false, 'platform_mail'],
            ['DEAMON_PLATFORM_MAIL_USERNAME', CoolifyEnvKind::Required, 'noreply@codron.co', false, 'platform_mail'],
            ['DEAMON_PLATFORM_MAIL_PASSWORD', CoolifyEnvKind::Required, null, true, 'platform_mail_password'],
            ['DEAMON_PLATFORM_MAIL_FROM_ADDRESS', CoolifyEnvKind::Static, 'noreply@codron.co', false, 'platform_mail'],
            ['DEAMON_PLATFORM_MAIL_FROM_NAME', CoolifyEnvKind::Static, 'Deamon Support Team', false, 'platform_mail'],
        ];
    }

    /**
     * Coolify injects these. Catalog lists them so operators see them; Plane never writes them.
     *
     * @return list<array{0: string, 1: CoolifyEnvKind, 2: ?string, 3: bool, 4: ?string}>
     */
    private static function coolifySkip(): array
    {
        return [
            ['APP_URL', CoolifyEnvKind::Skip, '{{coolify.SERVICE_URL_APP}}', false, 'coolify_url'],
            ['DEAMON_SITE_HOST', CoolifyEnvKind::Skip, '{{coolify.SERVICE_FQDN_APP}}', false, 'coolify_host'],
            ['SERVICE_URL_APP', CoolifyEnvKind::Skip, '{{coolify}}', false, 'coolify_service'],
            ['SERVICE_FQDN_APP', CoolifyEnvKind::Skip, '{{coolify}}', false, 'coolify_service'],
            ['COOLIFY_BRANCH', CoolifyEnvKind::Skip, '{{coolify}}', false, 'coolify_git'],
            ['SOURCE_COMMIT', CoolifyEnvKind::Skip, '{{coolify}}', false, 'coolify_git'],
        ];
    }
}
