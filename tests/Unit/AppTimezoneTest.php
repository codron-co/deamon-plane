<?php

namespace Tests\Unit;

use Tests\TestCase;

class AppTimezoneTest extends TestCase
{
    public function test_plane_uses_europe_istanbul(): void
    {
        $this->assertSame('Europe/Istanbul', config('app.timezone'));
        $this->assertSame('Europe/Istanbul', date_default_timezone_get());
        $this->assertSame('+03:00', now()->format('P'));
    }

    public function test_mysql_session_timezone_defaults_to_plus_three(): void
    {
        $this->assertSame('+03:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+03:00', config('database.connections.mariadb.timezone'));
    }
}
