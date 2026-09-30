<?php

namespace Tests\Feature\Coolify;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\CoolifySetting;
use App\Models\Site;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaptureDbSecretsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_missing_copies_and_keeps_existing_ones(): void
    {
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        CoolifySetting::factory()->create(['base_url' => 'https://coolify.test', 'api_token' => 't']);

        $fresh = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active, 'channel' => Channel::Main, 'coolify_app_uuid' => 'app-a',
        ]);
        $kept = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active, 'channel' => Channel::Main, 'coolify_app_uuid' => 'app-b',
        ]);
        $kept->forceFill(['db_password_encrypted' => 'older-copy'])->save();

        Http::fake(fn (Request $request) => Http::response([
            ['key' => 'DB_PASSWORD', 'value' => 'live-db', 'uuid' => 'e1'],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'live-root', 'uuid' => 'e2'],
        ], 200));

        $this->artisan('ops:capture-db-secrets')->assertSuccessful();

        $this->assertSame('live-db', $fresh->refresh()->db_password_encrypted);
        $this->assertSame('live-root', $fresh->mysql_root_password_encrypted);
        $this->assertSame('older-copy', $kept->refresh()->db_password_encrypted);
        $this->assertSame('live-root', $kept->mysql_root_password_encrypted);
    }
}
