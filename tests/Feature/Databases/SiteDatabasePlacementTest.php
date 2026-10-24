<?php

namespace Tests\Feature\Databases;

use App\Models\DatabaseServer;
use App\Models\Site;
use App\Services\Databases\SiteDatabasePlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDatabasePlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_come_from_the_slug(): void
    {
        $site = Site::factory()->create(['slug' => 'basut-silo']);

        $this->assertSame(['database' => 'site_basut_silo', 'username' => 'site_basut_silo'], SiteDatabasePlacement::namesFor($site));
    }

    public function test_long_slugs_keep_usernames_within_mysql_limits_and_distinct(): void
    {
        $a = Site::factory()->create(['slug' => 'beyazoglu-otomotiv-servis-istanbul-anadolu']);
        $b = Site::factory()->create(['slug' => 'beyazoglu-otomotiv-servis-istanbul-avrupa']);

        $na = SiteDatabasePlacement::namesFor($a);
        $nb = SiteDatabasePlacement::namesFor($b);

        $this->assertLessThanOrEqual(32, strlen($na['username']));
        $this->assertLessThanOrEqual(64, strlen($na['database']));
        $this->assertNotSame($na['username'], $nb['username']);
        $this->assertTrue(SiteDatabasePlacement::isSafeIdentifier($na['username']));
        $this->assertTrue(SiteDatabasePlacement::isSafeIdentifier($na['database']));
    }

    public function test_a_site_without_a_server_uses_its_own_compose_mysql(): void
    {
        $site = Site::factory()->create();

        $this->assertSame([
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'deamon',
            'DB_USERNAME' => 'deamon',
        ], SiteDatabasePlacement::envFor($site));
    }

    public function test_a_shared_site_points_at_the_server(): void
    {
        $server = DatabaseServer::factory()->create(['host' => 'deamon-db-mysql', 'port' => 3306]);
        $site = Site::factory()->create([
            'database_server_id' => $server->id,
            'db_database' => 'site_izyem',
            'db_username' => 'site_izyem',
        ]);

        $this->assertSame([
            'DB_HOST' => 'deamon-db-mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'site_izyem',
            'DB_USERNAME' => 'site_izyem',
        ], SiteDatabasePlacement::envFor($site));
    }

    public function test_admin_password_is_encrypted_and_hidden(): void
    {
        $server = DatabaseServer::factory()->create(['admin_password' => 'very-secret-admin']);

        $this->assertNotSame('very-secret-admin', $server->getRawOriginal('admin_password'));
        $this->assertSame('very-secret-admin', $server->fresh()->admin_password);
        $this->assertArrayNotHasKey('admin_password', $server->toArray());
    }
}
