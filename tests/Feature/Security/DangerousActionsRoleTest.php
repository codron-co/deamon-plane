<?php

namespace Tests\Feature\Security;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CloudflareSetting;
use App\Models\CoolifyConnection;
use App\Models\GithubSetting;
use App\Models\MailServer;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\ThemeGitConnection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Audit P03 / P08: irreversible and fleet-secret actions are Super Admin only,
 * in-use connections cannot be removed, and a changed target never receives the
 * stored secret.
 */
class DangerousActionsRoleTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_operator_is_refused_every_dangerous_action(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $account = CloudflareSetting::factory()->withToken()->create(['account_id' => str_repeat('b', 32)]);
        $gitConnection = ThemeGitConnection::factory()->create();
        $mailServer = MailServer::factory()->create();
        $archived = Site::factory()->create(['coolify_app_uuid' => 'archived-app']);
        $archived->delete();
        $live = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'live-app',
        ]);
        GithubSetting::factory()->create();

        $operator = $this->userWith(OpsRole::Operator);

        $requests = [
            ['DELETE', route('ops.sites.purge', $archived), []],
            ['POST', route('ops.sites.bulk.purge'), ['site_ids' => [$archived->id]]],
            ['POST', route('ops.sites.agent-secret', $live), []],
            ['PUT', route('ops.coolify.update', $connection), ['name' => 'Moved', 'base_url' => 'https://elsewhere.test', 'api_token' => 'new-token']],
            ['DELETE', route('ops.coolify.destroy', $connection), []],
            ['PUT', route('ops.cloudflare.update', $account), ['name' => 'CF']],
            ['DELETE', route('ops.cloudflare.destroy', $account), []],
            ['DELETE', route('ops.cloudflare.zones.destroy', ['account' => $account, 'zone' => self::ZONE_ID]), []],
            ['DELETE', route('ops.themes.git.destroy', $gitConnection), []],
            ['POST', route('ops.settings.deamon-git.disconnect'), []],
            ['DELETE', route('ops.settings.deamon-git.pat.clear'), []],
            ['PUT', route('ops.deskron.update'), ['application_id' => 'abc123']],
            ['PUT', route('ops.mail-servers.update', $mailServer), ['name' => 'Renamed']],
            ['DELETE', route('ops.mail-servers.destroy', $mailServer), []],
        ];

        foreach ($requests as [$method, $url, $payload]) {
            $this->actingAs($operator)
                ->call($method, $url, $payload)
                ->assertForbidden();
        }

        $this->assertNotNull(Site::withTrashed()->find($archived->id));
        $this->assertNotNull(CoolifyConnection::query()->find($connection->id));
        $this->assertSame('https://coolify.test', $connection->fresh()->base_url);
        $this->assertNotNull(CloudflareSetting::query()->find($account->id));
        $this->assertNotNull(ThemeGitConnection::query()->find($gitConnection->id));
        $this->assertNotNull(MailServer::query()->find($mailServer->id));
        Http::assertNothingSent();
    }

    public function test_operator_still_saves_coolify_defaults(): void
    {
        $connection = CoolifyConnection::factory()->create();

        $this->actingAs($this->userWith(OpsRole::Operator))
            ->put(route('ops.coolify.update', $connection), [
                'name' => $connection->name,
                'base_url' => $connection->base_url,
                'is_enabled' => '1',
                'default_server_uuid' => 'srv_other',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('srv_other', $connection->fresh()->default_server_uuid);
    }

    public function test_hard_delete_is_capped_per_request(): void
    {
        $sites = Site::factory()->count(11)->create();

        $this->actingAs($this->userWith(OpsRole::SuperAdmin))
            ->post(route('ops.sites.bulk.purge'), ['site_ids' => $sites->pluck('id')->all()])
            ->assertRedirect()
            ->assertSessionHas('error', __('site_ops.bulk.purge_limit', ['limit' => 10]));

        $this->assertSame(11, Site::query()->count());
        Http::assertNothingSent();
    }

    public function test_in_use_connection_account_and_zone_cannot_be_removed(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $account = CloudflareSetting::factory()->withToken()->create(['account_id' => str_repeat('b', 32)]);
        $site = Site::factory()->create([
            'coolify_connection_id' => $connection->id,
            'cloudflare_setting_id' => $account->id,
        ]);
        SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => 'in-use.example.test',
            'cloudflare_zone_id' => self::ZONE_ID,
        ]);
        $admin = $this->userWith(OpsRole::SuperAdmin);

        $this->actingAs($admin)
            ->delete(route('ops.coolify.destroy', $connection))
            ->assertSessionHas('error', __('coolify.errors.connection_in_use', ['count' => 1]));

        $this->actingAs($admin)
            ->delete(route('ops.cloudflare.destroy', $account))
            ->assertSessionHas('error', __('cloudflare.errors.account_in_use', ['count' => 1]));

        $this->actingAs($admin)
            ->delete(route('ops.cloudflare.zones.destroy', ['account' => $account, 'zone' => self::ZONE_ID]))
            ->assertSessionHas('error', __('cloudflare.errors.zone_in_use'));

        $this->assertNotNull(CoolifyConnection::query()->find($connection->id));
        $this->assertNotNull(CloudflareSetting::query()->find($account->id));
        Http::assertNothingSent();
    }

    public function test_new_coolify_url_needs_the_token_again(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $admin = $this->userWith(OpsRole::SuperAdmin);

        $this->actingAs($admin)
            ->put(route('ops.coolify.update', $connection), [
                'name' => $connection->name,
                'base_url' => 'https://elsewhere.test',
                'is_enabled' => '1',
            ])
            ->assertSessionHasErrors('api_token');

        $this->assertSame('https://coolify.test', $connection->fresh()->base_url);

        $this->actingAs($admin)
            ->post(route('ops.coolify.test', $connection), ['base_url' => 'https://elsewhere.test'])
            ->assertSessionHas('error', __('coolify.errors.token_required_for_new_url'));

        Http::assertNothingSent();

        $this->actingAs($admin)
            ->put(route('ops.coolify.update', $connection), [
                'name' => $connection->name,
                'base_url' => 'https://elsewhere.test',
                'api_token' => 'token-for-elsewhere',
                'is_enabled' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://elsewhere.test', $connection->fresh()->base_url);
    }

    public function test_smtp_login_is_super_admin_only_and_a_new_host_needs_the_password(): void
    {
        $settings = PlatformMailSetting::current();
        $settings->forceFill([
            'host' => 'smtp.saved.test',
            'port' => 465,
            'username' => 'alerts@example.test',
            'password' => 'saved-smtp-password',
        ])->save();

        $this->actingAs($this->userWith(OpsRole::Operator))
            ->put(route('ops.platform-mail.update'), [
                'host' => 'smtp.attacker.test',
                'port' => 465,
                'from_name' => 'Plane Ops',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = PlatformMailSetting::current();
        $this->assertSame('smtp.saved.test', $fresh->host);
        $this->assertSame('alerts@example.test', $fresh->username);
        $this->assertSame('Plane Ops', $fresh->from_name);

        $admin = $this->userWith(OpsRole::SuperAdmin);
        $this->actingAs($admin)
            ->put(route('ops.platform-mail.update'), [
                'host' => 'smtp.new.test',
                'port' => 465,
                'username' => 'alerts@example.test',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame('smtp.saved.test', PlatformMailSetting::current()->host);

        $this->actingAs($admin)
            ->put(route('ops.platform-mail.update'), [
                'host' => 'smtp.new.test',
                'port' => 465,
                'username' => 'alerts@example.test',
                'password' => 'new-smtp-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('smtp.new.test', PlatformMailSetting::current()->host);
    }

    public function test_operator_does_not_see_dangerous_controls(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $site = Site::factory()->create(['coolify_connection_id' => $connection->id]);
        $site->delete();
        $operator = $this->userWith(OpsRole::Operator);

        $this->actingAs($operator)
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertDontSee(__('coolify.danger.title'), false)
            ->assertSee(__('ops.super_admin_only'), false);

        $this->actingAs($operator)
            ->get(route('ops.sites.archived'))
            ->assertOk()
            ->assertDontSee(route('ops.sites.purge', $site), false);
    }

    private function userWith(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
