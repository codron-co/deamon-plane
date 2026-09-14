<?php

namespace Tests\Unit\Coolify;

use App\Enums\CoolifyEnvKind;
use App\Services\Coolify\EnvCatalog\EnvExampleParser;
use PHPUnit\Framework\TestCase;

class EnvExampleParserTest extends TestCase
{
    public function test_parses_kinds_from_value_tokens(): void
    {
        $rows = (new EnvExampleParser)->parse(implode("\n", [
            '# header comment block',
            '',
            '# Laravel app key.',
            '#@secret',
            'APP_KEY={{site.app_key}}',
            'DEAMON_SITE_NAME={{site.name}}',
            'CONTROL_PLANE_HOST_ALLOWLIST={{plane.host}}',
            '#@secret',
            'DB_PASSWORD={{generated}}',
            'APP_URL={{coolify.SERVICE_URL_APP}}',
            'SERVICE_FQDN_APP={{coolify}}',
            'DEAMON_PLATFORM_MAIL_PASSWORD=',
            'APP_TIMEZONE="Europe/Istanbul"',
            "QUOTED='single quoted'",
            'export EXPORTED=value',
            '# TRUSTED_PROXIES=10.0.0.0/8',
            'lowercase=ignored',
            'APP_KEY=duplicate-ignored',
        ]));

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->key] = $row;
        }

        $this->assertSame([
            'APP_KEY', 'DEAMON_SITE_NAME', 'CONTROL_PLANE_HOST_ALLOWLIST', 'DB_PASSWORD', 'APP_URL',
            'SERVICE_FQDN_APP', 'DEAMON_PLATFORM_MAIL_PASSWORD', 'APP_TIMEZONE', 'QUOTED', 'EXPORTED',
        ], array_keys($byKey));

        $this->assertSame(CoolifyEnvKind::Site, $byKey['APP_KEY']->kind);
        $this->assertTrue($byKey['APP_KEY']->isSecret);
        $this->assertSame('Laravel app key.', $byKey['APP_KEY']->description);
        $this->assertSame('{{site.app_key}}', $byKey['APP_KEY']->value);

        $this->assertSame(CoolifyEnvKind::Site, $byKey['DEAMON_SITE_NAME']->kind);
        $this->assertFalse($byKey['DEAMON_SITE_NAME']->isSecret);
        $this->assertNull($byKey['DEAMON_SITE_NAME']->description);

        $this->assertSame(CoolifyEnvKind::Site, $byKey['CONTROL_PLANE_HOST_ALLOWLIST']->kind);
        $this->assertSame(CoolifyEnvKind::Generated, $byKey['DB_PASSWORD']->kind);
        $this->assertTrue($byKey['DB_PASSWORD']->isSecret);
        $this->assertSame(CoolifyEnvKind::Skip, $byKey['APP_URL']->kind);
        $this->assertSame(CoolifyEnvKind::Skip, $byKey['SERVICE_FQDN_APP']->kind);
        $this->assertSame(CoolifyEnvKind::Required, $byKey['DEAMON_PLATFORM_MAIL_PASSWORD']->kind);
        $this->assertNull($byKey['DEAMON_PLATFORM_MAIL_PASSWORD']->value);
        $this->assertSame(CoolifyEnvKind::Static, $byKey['APP_TIMEZONE']->kind);
        $this->assertSame('Europe/Istanbul', $byKey['APP_TIMEZONE']->value);
        $this->assertSame('single quoted', $byKey['QUOTED']->value);
        $this->assertSame('value', $byKey['EXPORTED']->value);
    }

    public function test_description_is_only_the_comment_block_directly_above_the_key(): void
    {
        $rows = (new EnvExampleParser)->parse(implode("\n", [
            '# Far away comment.',
            '',
            '# Line one.',
            '# Line two.',
            'ONE=1',
            '',
            '# Disabled example:',
            '# TWO_EXAMPLE=x',
            'TWO=2',
            '#@unknown-directive',
            '# Three.',
            'THREE=3',
        ]));

        $this->assertSame('Line one. Line two.', $rows[0]->description);
        $this->assertNull($rows[1]->description);
        $this->assertSame('Three.', $rows[2]->description);
    }

    public function test_windows_line_endings_and_secret_reset_between_keys(): void
    {
        $rows = (new EnvExampleParser)->parse("#@secret\r\nA=1\r\nB=2\r\n");

        $this->assertTrue($rows[0]->isSecret);
        $this->assertFalse($rows[1]->isSecret);
    }

    public function test_real_fixture_has_no_static_compose_or_platform_mail_rows(): void
    {
        $rows = (new EnvExampleParser)->parse((string) file_get_contents(__DIR__.'/../../Fixtures/deamon/env-production.example'));
        $keys = array_map(static fn ($row): string => $row->key, $rows);

        $this->assertContains('APP_KEY', $keys);
        $this->assertContains('APP_ENV', $keys);
        $this->assertContains('DB_PASSWORD', $keys);
        $this->assertContains('MYSQL_ROOT_PASSWORD', $keys);
        $this->assertContains('CONTROL_PLANE_AGENT_SECRET', $keys);
        $this->assertContains('DEAMON_CHANNEL', $keys);
        $this->assertNotContains('DEAMON_DEFAULT_ADMIN_PASSWORD', $keys);
        $this->assertNotContains('DEAMON_PLATFORM_MAIL_PASSWORD', $keys);
        $this->assertNotContains('APP_TIMEZONE', $keys);
        $this->assertNotContains('DB_HOST', $keys);
        $this->assertNotContains('TRUSTED_PROXIES', $keys);
        $this->assertNotContains('SERVICE_URL_APP', $keys);
        $this->assertNotContains('APP_URL', $keys);
        $this->assertNotContains('COOLIFY_BRANCH', $keys);

        foreach ($rows as $row) {
            $this->assertNotSame(CoolifyEnvKind::Static, $row->kind, $row->key.' must not be static — compose owns constants');
            $this->assertNotSame(CoolifyEnvKind::Skip, $row->kind, $row->key.' must not be a coolify skip row');
        }
    }
}
